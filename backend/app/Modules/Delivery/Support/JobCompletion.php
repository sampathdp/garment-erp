<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Support;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Support\CuttingChecklists;
use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryDocument;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sewing\Enums\SewingJobStatus;
use App\Modules\Sewing\Models\SewingJob;

/**
 * Everything that must hold before a job can be marked Completed (Phase 08 §3, business
 * invariant 11). Used by CompleteJob under the row locks and by the delivery resource.
 *
 *  - production: cutting and sewing completed;
 *  - checks:     every mandatory cutting task and quality check done (re-checked, not assumed);
 *  - issues:     no unresolved blocking production issue;
 *  - delivery:   delivered, with the documents its method requires;
 *  - balance:    VERIFIED balance = 0 (COD collections verified by accounts). Write-offs are
 *                not implemented yet, so a remaining balance always blocks completion.
 */
final class JobCompletion
{
    public function __construct(private readonly CuttingChecklists $checklists) {}

    /** @return array<string, list<string>> empty when the job can be completed */
    public function blockers(Order $order, Delivery $delivery): array
    {
        $blockers = [];

        $cutting = CuttingJob::query()->where('order_id', $order->id)->first();
        $sewing = SewingJob::query()->where('order_id', $order->id)->first();
        $production = [];
        if ($cutting?->status !== CuttingJobStatus::Completed) {
            $production[] = 'Cutting is not completed.';
        }
        if ($sewing?->status !== SewingJobStatus::Completed) {
            $production[] = 'Sewing is not completed.';
        }
        if ($production !== []) {
            $blockers['production'] = $production;
        }

        if ($cutting !== null) {
            $checks = [];
            $run = $this->checklists->current($cutting, ChecklistStage::Cutting);
            foreach ($run === null ? ['No cutting checklist.'] : $this->checklists->openMandatory($run) as $label) {
                $checks[] = "Cutting task not completed: {$label}";
            }
            $qc = $this->checklists->current($cutting, ChecklistStage::QualityControl);
            foreach ($qc === null ? ['No quality checks.'] : $this->checklists->failingMandatoryChecks($qc) as $label) {
                $checks[] = "Quality check: {$label}";
            }
            if ($checks !== []) {
                $blockers['checks'] = $checks;
            }
        }

        $issues = ProductionIssue::query()->where('order_id', $order->id)->blocking()->orderBy('id')->get(['id', 'type'])
            ->map(fn (ProductionIssue $i): string => "Issue #{$i->id} ({$i->type->label()}) is not resolved.")->values()->all();
        if ($issues !== []) {
            $blockers['issues'] = $issues;
        }

        if ($delivery->status !== DeliveryStatus::Delivered || $order->status !== OrderStatus::Delivered) {
            $blockers['delivery'] = ['The job is not delivered yet.'];
        }
        if (($missing = self::missingDocuments($delivery)) !== []) {
            $blockers['documents'] = array_map(fn (array $set): string => 'Missing document: '.implode(' or ', array_map(fn (DeliveryDocumentType $t) => $t->label(), $set)), $missing);
        }

        $outstanding = DeliveryWorkflow::outstanding($order);
        if ($outstanding > 0) {
            $pending = (int) Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::PendingVerification->value)->sum('amount_minor');
            $blockers['balance'] = ['Verified balance outstanding: '.DeliveryWorkflow::money($outstanding, $order->currency).'.'
                .($pending > 0 ? ' '.DeliveryWorkflow::money($pending, $order->currency).' is waiting for verification.' : '')];
        }

        return $blockers;
    }

    /** @return list<list<DeliveryDocumentType>> requirement sets (any one type of a set satisfies it) still missing */
    public static function missingDocuments(Delivery $delivery): array
    {
        $required = DeliveryDocumentType::requiredFor($delivery->method);
        if ($delivery->method === null) {
            return [[DeliveryDocumentType::DeliverySheet, DeliveryDocumentType::Waybill, DeliveryDocumentType::DeliveryPhoto]]; // method not chosen yet
        }
        $types = $delivery->relationLoaded('documents') ? $delivery->documents->pluck('type')
            : DeliveryDocument::query()->where('delivery_id', $delivery->id)->distinct()->pluck('type');
        $present = $types->map(fn ($t) => $t instanceof DeliveryDocumentType ? $t : DeliveryDocumentType::from((string) $t))->all();

        return array_values(array_filter([$required], fn (array $set) => array_intersect(array_map(fn ($t) => $t->value, $set), array_map(fn ($t) => $t->value, $present)) === []));
    }
}
