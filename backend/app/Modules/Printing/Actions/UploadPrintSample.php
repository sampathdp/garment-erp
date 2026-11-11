<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\SampleFileKind;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Models\PrintSampleDocument;
use App\Modules\Printing\Rules\SampleImage;
use App\Modules\Printing\Support\PrintingNotifications;
use App\Modules\Printing\Support\PrintingWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Operation Admin uploads a printing sample: the initial sample after sending, or a
 * corrected sample after a rejection. Every upload is a NEW immutable sample version
 * (sample_no + 1); earlier samples and their decisions are never overwritten.
 *
 * Files go on the private disk BEFORE the transaction (no file I/O inside it) and are removed
 * again if the transaction fails. The job moves to "sample pending approval", the order to
 * print_sample_review, and the Page Owners are notified.
 */
final class UploadPrintSample
{
    public function __construct(
        private readonly StoreDocument $documents,
        private readonly PrintingWorkflow $workflow,
        private readonly PrintingNotifications $notify,
    ) {}

    /** @param  list<UploadedFile>  $related */
    public function handle(User $actor, PrintJob $job, int $expectedVersion, UploadedFile $image, array $related, ?string $notes): PrintSample
    {
        $stored = [];
        try {
            $stored[] = ['kind' => SampleFileKind::Image, 'file' => $this->documents->put($image, DocumentCategory::SamplePhoto, SampleImage::detect($image))];
            foreach ($related as $file) {
                $stored[] = ['kind' => SampleFileKind::Document, 'file' => $this->documents->put($file, DocumentCategory::SamplePhoto)];
            }

            return DB::transaction(function () use ($actor, $job, $expectedVersion, $stored, $notes): PrintSample {
                [$job, $order] = $this->workflow->lock($job, $expectedVersion);
                $this->workflow->ensureOrderStage($job, $order);
                $resubmission = $job->status === PrintJobStatus::SampleRejected;

                $sample = new PrintSample(['print_job_id' => $job->id, 'notes' => $notes]);
                $sample->forceFill([
                    'sample_no' => (int) PrintSample::query()->where('print_job_id', $job->id)->max('sample_no') + 1,
                    'uploaded_by' => $actor->id,
                ])->save();

                foreach ($stored as $entry) {
                    $document = $this->documents->record($entry['file'], DocumentCategory::SamplePhoto, $actor, $order->branch_id);
                    PrintSampleDocument::query()->create(['print_sample_id' => $sample->id, 'document_id' => $document->id, 'kind' => $entry['kind']]);
                    if ($entry['kind'] === SampleFileKind::Image) {
                        $sample->forceFill(['photo_document_id' => $document->id])->save();
                    }
                }

                $this->workflow->transition($job, $order, PrintJobStatus::SamplePendingApproval, $actor, 'printing.sample_uploaded', $notes,
                    ['sample_id' => $sample->id, 'sample_no' => $sample->sample_no, 'resubmission' => $resubmission, 'files' => count($stored)]);
                $this->workflow->save($job);
                $this->notify->sampleUploaded($job, $order, $sample, $actor);

                return $sample;
            });
        } catch (Throwable $e) {
            foreach ($stored as $entry) {
                $this->documents->discard($entry['file']);
            }
            throw $e;
        }
    }
}
