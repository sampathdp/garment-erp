<?php

declare(strict_types=1);

namespace App\Modules\Printing\Queries;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Queries\ConfirmedGarmentList;
use App\Modules\BulkProduction\Queries\JobCard;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Support\Facades\URL;

/**
 * Read models of the two printing documents, assembled live from the authoritative records:
 *  - the Job Card (BulkProduction JobCard), with the design image served through the printing
 *    job's own signed download route (printing users need no bulk access);
 *  - the confirmed name/number list version the printing job was opened from.
 */
final class PrintJobCard
{
    public function __construct(private readonly JobCard $card, private readonly ConfirmedGarmentList $list) {}

    /** @return array<string, mixed> */
    public function jobCard(PrintJob $job): array
    {
        $card = $this->card->for($this->bulk($job));
        $preview = DesignVersionFile::query()->where('design_version_id', $job->getAttribute('design_version_id'))
            ->where('kind', DesignFileKind::Preview->value)->orderBy('id')->value('document_id');
        $card['design_image_url'] = $preview === null ? null : URL::temporarySignedRoute('print-jobs.documents.download',
            now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5)), ['job' => $job->id, 'document' => (int) $preview]);
        $card['printing'] = ['id' => $job->id, 'status' => ['value' => $job->status->value, 'label' => $job->status->label()]];

        return $card;
    }

    /** @return array{version: array<string, mixed>, schema: array<string, mixed>, items: list<array<string, mixed>>} */
    public function nameList(PrintJob $job): array
    {
        return $this->list->for($this->bulk($job), (int) $job->getAttribute('garment_list_version_id'));
    }

    private function bulk(PrintJob $job): BulkJob
    {
        return BulkJob::query()->findOrFail($job->getAttribute('bulk_job_id'));
    }
}
