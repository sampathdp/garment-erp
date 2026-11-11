<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Delivery\Enums\CodStatus;
use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Delivery\Enums\DeliveryMethod;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryDocument;
use App\Modules\Delivery\Support\DeliveryNotifications;
use App\Modules\Delivery\Support\DeliveryWorkflow;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivery workflow (Phase 08):
 *
 *  - dispatch():     courier / own vehicle; order ready_for_delivery → out_for_delivery. The COD
 *                    amount is the outstanding (verified) balance at dispatch — the balance does
 *                    NOT have to be paid first (client decision).
 *  - deliver():      from dispatched, or straight from ready for a customer pickup; order →
 *                    delivered; recipient name recorded; cash collected on delivery is recorded
 *                    as a `cod` payment PENDING verification by accounts (separation of duties).
 *  - addDocument():  delivery sheet / waybill / delivery or customer photo on the private disk.
 */
final class ManageDelivery
{
    public function __construct(
        private readonly DeliveryWorkflow $workflow,
        private readonly TransitionOrder $transition,
        private readonly StoreDocument $documents,
        private readonly RecordPayment $payments,
        private readonly DeliveryNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{method: DeliveryMethod, courier_name: ?string, tracking_no: ?string, scheduled_date: ?string, notes: ?string}  $data */
    public function dispatch(User $actor, Delivery $delivery, int $expectedVersion, array $data): Delivery
    {
        if ($data['method'] === DeliveryMethod::Pickup) {
            throw new ApiException(422, 'validation_failed', 'A customer pickup is not dispatched; record the delivery directly.', ['method' => ['A customer pickup is not dispatched.']]);
        }

        return DB::transaction(function () use ($actor, $delivery, $expectedVersion, $data): Delivery {
            [$delivery, $order] = $this->workflow->lock($delivery, $expectedVersion);
            if ($delivery->status !== DeliveryStatus::Ready || $order->status !== OrderStatus::ReadyForDelivery) {
                throw ApiException::conflict('invalid_state', 'Only a job that is ready for delivery can be dispatched.');
            }

            $cod = DeliveryWorkflow::outstanding($order);
            $delivery->fill(['method' => $data['method'], 'courier_name' => $data['courier_name'], 'tracking_no' => $data['tracking_no'], 'scheduled_date' => $data['scheduled_date'], 'notes' => $data['notes']]);
            $delivery->forceFill(['status' => DeliveryStatus::Dispatched, 'dispatched_by' => $actor->id, 'dispatched_at' => now(),
                'cod_amount_minor' => $cod, 'cod_status' => $cod > 0 ? CodStatus::Pending : CodStatus::NotApplicable]);
            $this->workflow->save($delivery);
            $this->transition->handle($order, OrderStatus::OutForDelivery, $actor, 'Dispatched ('.$data['method']->label().').');
            $this->audit->record('delivery.dispatched', $delivery, ['after' => ['method' => $data['method']->value, 'cod_amount_minor' => $cod, 'tracking_no' => $data['tracking_no']]], actor: $actor, branchId: $order->branch_id);
            $this->notify->dispatched($delivery, $order, $actor);

            return $delivery;
        });
    }

    public function deliver(User $actor, Delivery $delivery, int $expectedVersion, ?DeliveryMethod $method, string $receivedBy, int $codCollected, ?string $notes): Delivery
    {
        return DB::transaction(function () use ($actor, $delivery, $expectedVersion, $method, $receivedBy, $codCollected, $notes): Delivery {
            [$delivery, $order] = $this->workflow->lock($delivery, $expectedVersion);
            $pickup = $delivery->status === DeliveryStatus::Ready;
            if ($pickup && $method !== DeliveryMethod::Pickup) {
                throw ApiException::conflict('not_dispatched', 'Dispatch the job first, or record a customer pickup.');
            }
            if (! in_array($delivery->status, [DeliveryStatus::Ready, DeliveryStatus::Dispatched], true)
                || ! in_array($order->status, [OrderStatus::ReadyForDelivery, OrderStatus::OutForDelivery], true)) {
                throw ApiException::conflict('invalid_state', 'This job cannot be marked delivered now.');
            }
            $outstanding = DeliveryWorkflow::outstanding($order);
            if ($codCollected > $outstanding) {
                throw new ApiException(422, 'validation_failed', 'The collected amount is more than the outstanding balance.', ['cod_collected_minor' => ['The collected amount is more than the outstanding balance ('.DeliveryWorkflow::money($outstanding, $order->currency).').']]);
            }

            if ($pickup) {
                $delivery->forceFill(['method' => DeliveryMethod::Pickup, 'cod_amount_minor' => $outstanding]);
            }
            $delivery->forceFill(['status' => DeliveryStatus::Delivered, 'delivered_by' => $actor->id, 'delivered_at' => now(), 'received_by_name' => $receivedBy,
                'notes' => $notes ?? $delivery->getAttribute('notes'), 'cod_collected_minor' => $codCollected > 0 ? $codCollected : null,
                'cod_status' => $codCollected > 0 ? CodStatus::Collected : ($delivery->cod_amount_minor > 0 ? CodStatus::Pending : CodStatus::NotApplicable)]);
            $this->workflow->save($delivery);
            $this->transition->handle($order, OrderStatus::Delivered, $actor, $pickup ? 'Collected by the customer.' : 'Delivered.');

            if ($codCollected > 0) {
                $payment = $this->payments->handle($actor, $order, ['amount_minor' => $codCollected, 'method' => PaymentMethod::Cod, 'reference_no' => "DEL-{$delivery->id}",
                    'payment_date' => BusinessDate::today()->toDateString(), 'notes' => 'Collected on delivery'], null, null);
                $payment->forceFill(['delivery_id' => $delivery->id])->save();
            }
            $this->audit->record('delivery.delivered', $delivery, ['after' => ['method' => $delivery->method?->value, 'received_by' => $receivedBy, 'cod_collected_minor' => $codCollected]], actor: $actor, branchId: $order->branch_id);
            $this->notify->delivered($delivery, $order, $codCollected > 0, $actor);

            return $delivery;
        });
    }

    public function addDocument(User $actor, Delivery $delivery, DeliveryDocumentType $type, UploadedFile $file, ?string $notes): DeliveryDocument
    {
        $stored = $this->documents->put($file, DocumentCategory::ProofOfDelivery);
        try {
            return DB::transaction(function () use ($actor, $delivery, $type, $stored, $notes): DeliveryDocument {
                [$delivery, $order] = $this->workflow->lock($delivery);
                if ($order->status === OrderStatus::Completed || $order->status === OrderStatus::Cancelled) {
                    throw ApiException::conflict('invalid_state', 'Documents cannot be added to a closed job.');
                }
                $document = $this->documents->record($stored, DocumentCategory::ProofOfDelivery, $actor, $order->branch_id);
                $row = DeliveryDocument::query()->create(['delivery_id' => $delivery->id, 'document_id' => $document->id, 'type' => $type, 'notes' => $notes, 'uploaded_by' => $actor->id]);
                $this->workflow->save($delivery);
                $this->audit->record('delivery.document_uploaded', $delivery, ['after' => ['type' => $type->value, 'document_id' => $document->id]], actor: $actor, branchId: $order->branch_id);

                return $row;
            });
        } catch (Throwable $e) {
            $this->documents->discard($stored);
            throw $e;
        }
    }
}
