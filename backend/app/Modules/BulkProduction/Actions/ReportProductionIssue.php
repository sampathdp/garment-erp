<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\BulkProduction\Models\ProductionIssueEvent;
use App\Modules\BulkProduction\Support\ProductionIssueNotifications;
use App\Modules\Cutting\Actions\RecordCuttingQuantities;
use App\Modules\Cutting\Enums\GarmentPart;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports a production issue into the central register — from a cutting job (damaged pieces,
 * incorrect cuts, missing sleeves/parts, missing items …) or from an order.
 *
 * Quantity rules (422): a quantity is required for piece and item categories and is ≥ 1; the
 * unresolved defective pieces of a cutting job can never exceed the pieces cut; missing items
 * can never exceed the pieces still uncut; nothing exceeds the ordered quantity.
 *
 * Only issue managers may decide that an issue is NOT blocking; otherwise the category default
 * applies. Notifies the Operation Admins and, for bulk-related issues, the Bulk Designer.
 */
final class ReportProductionIssue
{
    public function __construct(
        private readonly StoreDocument $documents,
        private readonly ProductionIssueNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{type: IssueType, description: string, quantity_affected?: int|null, part?: GarmentPart|null, size_label?: string|null, is_blocking?: bool|null}  $data
     */
    public function handle(User $actor, Order $order, ?CuttingJob $cuttingJob, array $data, ?UploadedFile $image): ProductionIssue
    {
        $stored = $image === null ? null : $this->documents->put($image, DocumentCategory::IssuePhoto);
        try {
            return DB::transaction(function () use ($actor, $order, $cuttingJob, $data, $stored): ProductionIssue {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($cuttingJob !== null) {
                    $cuttingJob = CuttingJob::query()->lockForUpdate()->findOrFail($cuttingJob->id);
                    if (! $cuttingJob->status->isOpen()) {
                        throw ApiException::conflict('invalid_state', 'This cutting job is completed.');
                    }
                }
                if (! $order->status->isActive()) {
                    throw ApiException::conflict('invalid_state', 'Issues can only be reported on active orders.');
                }

                $type = $data['type'];
                $quantity = $data['quantity_affected'] ?? null;
                $this->validateQuantity($type, $quantity, $order, $cuttingJob, $data);

                $documentId = $stored === null ? null : $this->documents->record($stored, DocumentCategory::IssuePhoto, $actor, $order->branch_id)->id;
                $blocking = $actor->can(Permissions::ISSUES_MANAGE) && isset($data['is_blocking']) ? (bool) $data['is_blocking'] : $type->blocksByDefault();

                $issue = new ProductionIssue([
                    'order_id' => $order->id, 'cutting_job_id' => $cuttingJob?->id, 'stage' => $cuttingJob !== null ? ChecklistStage::Cutting : self::stageOf($order->status),
                    'type' => $type, 'description' => $data['description'], 'quantity_affected' => $quantity,
                    'part' => $data['part'] ?? null, 'size_label' => $data['size_label'] ?? null,
                ]);
                $issue->forceFill(['branch_id' => $order->branch_id, 'status' => IssueStatus::Open, 'raised_by' => $actor->id, 'image_document_id' => $documentId, 'is_blocking' => $blocking, 'version' => 1])->save();
                ProductionIssueEvent::query()->create(['production_issue_id' => $issue->id, 'from_status' => null, 'to_status' => IssueStatus::Open, 'note' => 'Reported.', 'actor_id' => $actor->id]);

                $this->audit->record('issues.reported', $issue, ['after' => [
                    'order_id' => $order->id, 'cutting_job_id' => $cuttingJob?->id, 'type' => $type->value, 'quantity_affected' => $quantity,
                    'part' => ($data['part'] ?? null)?->value, 'is_blocking' => $blocking, 'image_document_id' => $documentId,
                ]], actor: $actor, branchId: $order->branch_id);
                $this->notify->reported($issue, $order, $actor);

                return $issue;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function validateQuantity(IssueType $type, ?int $quantity, Order $order, ?CuttingJob $job, array $data): void
    {
        $fail = fn (string $field, string $message) => throw new ApiException(422, 'validation_failed', $message, [$field => [$message]]);

        if ($type->requiresQuantity() && $quantity === null) {
            $fail('quantity_affected', 'Enter the quantity affected.');
        }
        if ($type === IssueType::MissingParts && ($data['part'] ?? null) === null) {
            $fail('part', 'Choose the missing part (sleeve, collar …).');
        }
        if ($quantity === null) {
            return;
        }
        if ($quantity < 1) {
            $fail('quantity_affected', 'The quantity affected must be at least 1.');
        }
        if ($quantity > $order->total_quantity) {
            $fail('quantity_affected', "The quantity affected cannot exceed the ordered quantity ({$order->total_quantity}).");
        }
        if ($job === null) {
            return;
        }

        $size = $data['size_label'] ?? null;
        $row = $size === null ? null : CuttingJobQuantity::query()->where('cutting_job_id', $job->id)->where('size_label', $size)->first();
        if ($size !== null && $row === null) {
            $fail('size_label', 'This size is not part of the cutting job.');
        }

        if ($type->isPieceDefect()) {
            $reported = RecordCuttingQuantities::reportedDefects($job);
            if ($row !== null && $quantity > $row->cut_quantity) {
                $fail('quantity_affected', "Only {$row->cut_quantity} pieces of size {$row->size_label} are cut.");
            }
            if ($reported + $quantity > $job->cut_quantity) {
                $left = max(0, $job->cut_quantity - $reported);
                $fail('quantity_affected', "Only {$left} cut pieces are not already reported as defective (cut: {$job->cut_quantity}, reported: {$reported}).");
            }
        } elseif ($type === IssueType::MissingItems) {
            $uncut = $row?->missing() ?? max(0, $job->planned_quantity - $job->cut_quantity);
            if ($quantity > $uncut) {
                $fail('quantity_affected', "Only {$uncut} items are still missing (not cut) on this job.");
            }
        }
    }

    public static function stageOf(OrderStatus $status): ChecklistStage
    {
        return match ($status) {
            OrderStatus::PrintPreparation, OrderStatus::PrintSampleReview, OrderStatus::Printing => ChecklistStage::PrintPreparation,
            OrderStatus::Cutting => ChecklistStage::Cutting,
            OrderStatus::Sewing => ChecklistStage::Sewing,
            OrderStatus::QualityCheck => ChecklistStage::QualityControl,
            OrderStatus::ReadyForDelivery, OrderStatus::OutForDelivery, OrderStatus::Delivered => ChecklistStage::Dispatch,
            default => ChecklistStage::BulkPreparation,
        };
    }
}
