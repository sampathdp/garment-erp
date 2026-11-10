<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Queries;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Support\BulkMeasurementRules;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentListReview;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Facades\URL;

/**
 * The Job Card: a READ MODEL assembled on every request from the authoritative records —
 * order (job no., quantity, material), product category, approved design preview, final design
 * link, bulk measurements (bulk meter, patch meter, collar-related and other configured fields)
 * and the confirmed name/number list version referenced by the bulk job. Nothing is copied into
 * a separate table, so the card is always up to date and can never disagree with its sources.
 */
final class JobCard
{
    public function __construct(private readonly BulkMeasurementRules $rules, private readonly GarmentListReview $review) {}

    /** @return array<string, mixed> */
    public function for(BulkJob $job): array
    {
        $order = Order::query()->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'order_type_id', 'required_delivery_date', 'is_urgent', 'total_quantity', 'material_details', 'status'])
            ->with(['customer:id,name', 'material:id,name', 'branch:id,code,name', 'page:id,code,name', 'orderType:id,name'])->findOrFail($job->order_id);
        $item = OrderItem::query()->select(['id', 'order_id', 'product_type_id', 'quantity'])->with('productType:id,name')->where('order_id', $order->id)->orderBy('sort_order')->firstOrFail();
        $task = DesignTask::query()->select(['id', 'approved_version_id', 'final_version_id'])->with('finalVersion:id,version_no,external_url')->find($job->getAttribute('design_task_id'));

        $previewFile = $task?->getAttribute('approved_version_id') === null ? null : DesignVersionFile::query()
            ->where('design_version_id', $task->getAttribute('approved_version_id'))->where('kind', DesignFileKind::Preview->value)->orderBy('id')->first(['id', 'document_id']);
        $ttl = now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5));

        $values = BulkJobMeasurement::query()->where('bulk_job_id', $job->id)->get()->keyBy('bulk_measurement_field_id');
        $measurements = $this->rules->fieldsFor($job)->filter(fn (BulkMeasurementField $f) => $f->show_on_job_card || $f->is_system)
            ->map(fn (BulkMeasurementField $f): array => [
                'key' => $f->key, 'label' => $f->label, 'unit' => $f->unit, 'is_required' => $f->is_required,
                'value' => BulkMeasurementRules::display($values->get($f->id)?->value, $f->decimals),
            ])->values();

        $listVersion = GarmentListVersion::query()->select(['id', 'garment_list_id', 'version_no', 'status', 'locked_at', 'total_quantity'])->find($job->getAttribute('garment_list_version_id'));
        $summary = $listVersion === null ? null : $this->review->review($listVersion, GarmentSchema::forProductType($item->product_type_id), $item->quantity);

        return [
            'job_no' => $order->order_no,
            'order_id' => $order->id,
            'customer' => $order->customer->name,
            'branch' => $order->branch->code,
            'page' => $order->page->code,
            'due_date' => $order->required_delivery_date->format('Y-m-d'),
            'is_urgent' => $order->is_urgent,
            'design_image_url' => $previewFile === null ? null : URL::temporarySignedRoute('bulk-jobs.documents.download', $ttl, ['job' => $job->id, 'document' => $previewFile->document_id]),
            'final_design_link' => $task?->finalVersion?->getAttribute('external_url'),
            'material' => $order->material?->name,
            'material_details' => $order->material_details,
            'product' => ['category' => $item->productType->name, 'order_type' => $order->orderType?->name],
            'quantity' => $order->total_quantity,
            'bulk_meter' => $measurements->firstWhere('key', BulkMeasurementField::BULK_METER),
            'patch_meter' => $measurements->firstWhere('key', BulkMeasurementField::PATCH_METER),
            'other_measurements' => $measurements->reject(fn (array $m) => in_array($m['key'], [BulkMeasurementField::BULK_METER, BulkMeasurementField::PATCH_METER], true))->values()->all(),
            'list' => $listVersion === null ? null : [
                'version_no' => $listVersion->version_no,
                'status' => $listVersion->status->value,
                'locked_at' => $listVersion->locked_at?->toIso8601ZuluString(),
                'is_approved_reference' => $job->getAttribute('approved_list_version_id') === $listVersion->id,
                'is_current' => app(BulkWorkflow::class)->listIsCurrentAndLocked($job),
                'total_quantity' => $summary['total_quantity'] ?? 0,
                'by_size' => array_map(fn (array $r) => ['size' => $r['size'], 'quantity' => $r['quantity']], $summary['by_size'] ?? []),
                'groups' => array_map(fn (array $g) => ['label' => $g['label'], 'quantity' => $g['quantity']], $summary['price_groups'] ?? []),
            ],
            'bulk' => [
                'status' => ['value' => $job->status->value, 'label' => $job->status->label()],
                'mode' => $job->mode?->value,
                'approved_version_no' => $job->approvedVersion()->value('version_no'),
                'completed_at' => $job->completed_at?->toIso8601ZuluString(),
            ],
        ];
    }
}
