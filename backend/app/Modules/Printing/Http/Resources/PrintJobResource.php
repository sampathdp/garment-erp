<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Resources;

use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\BulkProduction\Support\BulkMeasurementRules;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Printing\Enums\PrintDocument;
use App\Modules\Printing\Enums\SampleFileKind;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobEvent;
use App\Modules\Printing\Models\PrintJobGatePass;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Models\PrintSampleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Printing job for the printing dashboard (list) and the printing screen (detail). Files are
 * served through short-lived signed URLs that re-check the printing policy (PrintJobFiles).
 *
 * `documents` tells the client whether the Job Card and the name/number list can be printed;
 * the print endpoint enforces the same rule on the server (printDocuments).
 *
 * @mixin PrintJob
 */
final class PrintJobResource extends JsonResource
{
    private const COLLAR_METER = 'collar_meter';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('print-jobs.index');
        $order = $this->order;
        $bulk = $this->bulkJob;
        $ttl = now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5));
        $url = fn (int $documentId): string => URL::temporarySignedRoute('print-jobs.documents.download', $ttl, ['job' => $this->id, 'document' => $documentId]);
        $preview = $this->designVersion->files->first(fn (DesignVersionFile $f) => $f->kind === DesignFileKind::Preview);
        $measurements = $this->measurements();
        $latest = $this->samples->sortByDesc('sample_no')->first();
        $list = $this->garmentListVersion;

        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'job_no' => $order->order_no,
            'order' => [
                'id' => $order->id,
                'customer' => $order->customer->name,
                'due_date' => $order->required_delivery_date->format('Y-m-d'),
                'is_urgent' => $order->is_urgent,
                'quantity' => $order->total_quantity,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
            ],
            'branch' => $this->branch->code,
            'page' => ['code' => $order->page->code, 'name' => $order->page->name],
            'product_category' => $this->orderItem->productType->name,
            'material' => $order->material?->name,
            'quantity' => $order->total_quantity,
            'design_preview_url' => $preview === null ? null : $url($preview->document_id),
            'design_version_no' => $this->designVersion->version_no,
            'mode' => $this->mode->value,
            'bulk' => [
                'id' => $bulk->id,
                'producer' => $bulk->isExternal() ? $bulk->supplier?->name : $bulk->bulkDesigner?->name,
                'completed_at' => $bulk->completed_at?->toIso8601ZuluString(),
                'final_version_no' => $this->bulkVersion?->version_no,
                'transfer_url' => $this->bulkVersion?->getAttribute('transfer_url'),
                'files' => $this->bulkVersion?->files->map(fn (BulkVersionFile $f): array => [
                    'id' => $f->id, 'kind' => $f->kind->value, 'name' => $f->document->original_name, 'mime_type' => $f->document->mime_type,
                    'size_bytes' => $f->document->size_bytes, 'url' => $url($f->document_id),
                ])->values()->all() ?? [],
            ],
            'bulk_meter' => $measurements[BulkMeasurementField::BULK_METER] ?? null,
            'patch_meter' => $measurements[BulkMeasurementField::PATCH_METER] ?? null,
            'collar_meter' => $measurements[self::COLLAR_METER] ?? null,
            'other_measurements' => array_values(array_diff_key($measurements, array_flip([BulkMeasurementField::BULK_METER, BulkMeasurementField::PATCH_METER, self::COLLAR_METER]))),
            'list' => ['version_no' => $list->version_no, 'total_quantity' => $list->total_quantity, 'status' => $list->status->value],
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'name' => $this->supplier->name] : null,
            'recipient_name' => $this->getAttribute('recipient_name'),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'latest_sample' => $latest === null ? null : ['sample_no' => $latest->sample_no, 'review_status' => $latest->reviewStatus()->value],
            'created_at' => $this->created_at?->toIso8601ZuluString(),

            'sending' => $this->when($detail, fn (): array => [
                'sent_by' => $this->sender?->name,
                'sent_at' => $this->sent_at?->toIso8601ZuluString(),
                'printing_company' => $this->supplier?->name,
                'recipient_name' => $this->getAttribute('recipient_name'),
                'notes' => $this->getAttribute('send_notes'),
            ]),
            'samples' => $this->when($detail, fn (): array => $this->samples->sortByDesc('sample_no')->values()->map(fn (PrintSample $s): array => [
                'id' => $s->id,
                'version' => $s->sample_no,
                'is_current' => $latest !== null && $s->id === $latest->id,
                'is_approved' => $s->id === $this->getAttribute('approved_sample_id'),
                'notes' => $s->getAttribute('notes'),
                'uploaded_by' => $s->uploader?->name,
                'uploaded_at' => $s->created_at?->toIso8601ZuluString(),
                'review_status' => ['value' => $s->reviewStatus()->value, 'label' => $s->reviewStatus()->label()],
                'reviewed_by' => $s->recorder?->name,
                'reviewed_at' => $s->decided_at?->toIso8601ZuluString(),
                'rejection_reason' => $s->getAttribute('rejection_reason'),
                'comment' => $s->getAttribute('customer_comment'),
                'channel' => $s->channel?->value,
                'image_url' => ($image = $s->documents->first(fn (PrintSampleDocument $d) => $d->kind === SampleFileKind::Image)) === null ? null : $url($image->document_id),
                'documents' => $s->documents->filter(fn (PrintSampleDocument $d) => $d->kind === SampleFileKind::Document)->values()->map(fn (PrintSampleDocument $d): array => [
                    'name' => $d->document->original_name, 'mime_type' => $d->document->mime_type, 'size_bytes' => $d->document->size_bytes, 'url' => $url($d->document_id),
                ])->all(),
            ])->all()),
            'printing' => $this->when($detail, fn (): array => [
                'started_by' => $this->printingStarter?->name,
                'started_at' => $this->printing_started_at?->toIso8601ZuluString(),
                'completed_by' => $this->printingCompleter?->name,
                'completed_at' => $this->printing_completed_at?->toIso8601ZuluString(),
                'printed_quantity' => $this->printing_completed_at === null ? null : $this->printed_quantity,
                'notes' => $this->getAttribute('completion_notes'),
            ]),
            'gate_passes' => $this->when($detail, fn (): array => $this->gatePasses->sortByDesc('id')->values()->map(fn (PrintJobGatePass $g): array => [
                'id' => $g->id,
                'gate_pass_no' => $g->gate_pass_no,
                'gate_pass_date' => $g->gate_pass_date->format('Y-m-d'),
                'notes' => $g->getAttribute('notes'),
                'document' => $g->document === null ? null : ['name' => $g->document->original_name, 'url' => $url($g->document->id)],
                'uploaded_by' => $g->recorder->name,
                'uploaded_at' => $g->created_at?->toIso8601ZuluString(),
            ])->all()),
            'history' => $this->when($detail, fn (): array => $this->events->sortByDesc('id')->values()->map(fn (PrintJobEvent $e): array => [
                'from' => $e->from_status?->label(), 'to' => $e->to_status->label(), 'note' => $e->getAttribute('note'),
                'by' => $e->actor->name, 'at' => $e->created_at?->toIso8601ZuluString(),
            ])->all()),
            'documents' => $this->when($detail, fn (): array => $this->documentAccess()),
            'abilities' => $this->when($detail, fn (): array => [
                'send' => Gate::allows('send', $this->resource),
                'upload_sample' => Gate::allows('uploadSample', $this->resource),
                'review_sample' => Gate::allows('reviewSample', $this->resource),
                'start_printing' => Gate::allows('startPrinting', $this->resource),
                'complete_printing' => Gate::allows('completePrinting', $this->resource),
                'print_documents' => Gate::allows('printDocuments', $this->resource),
                'record_gate_pass' => Gate::allows('recordGatePass', $this->resource),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function documentAccess(): array
    {
        $print = Gate::inspect('printDocuments', $this->resource);
        $documents = [];
        foreach (PrintDocument::cases() as $document) {
            $documents[str_replace('-', '_', $document->value)] = [
                'label' => $document->label(),
                'viewable' => true,
                'printable' => $print->allowed(),
                'print_locked_reason' => $print->allowed() ? null : $print->message(),
            ];
        }

        return $documents;
    }

    /** @return array<string, array{key: string, label: string, unit: string, value: string|null}> */
    private function measurements(): array
    {
        $rows = [];
        foreach ($this->bulkJob->measurements as $m) {
            /** @var BulkJobMeasurement $m */
            $rows[$m->field->key] = ['key' => $m->field->key, 'label' => $m->field->label, 'unit' => $m->field->unit, 'value' => BulkMeasurementRules::display($m->value, $m->field->decimals)];
        }

        return $rows;
    }
}
