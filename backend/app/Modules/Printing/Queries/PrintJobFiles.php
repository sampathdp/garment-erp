<?php

declare(strict_types=1);

namespace App\Modules\Printing\Queries;

use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Documents\Models\Document;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobGatePass;
use App\Modules\Printing\Models\PrintSampleDocument;

/**
 * Files a printing job may serve: its sample images and related documents, its gate pass
 * documents, the files of the final bulk version and of the approved design version it was
 * opened from. Anything else is a 404, even for a valid document id.
 */
final class PrintJobFiles
{
    public function find(PrintJob $job, int $documentId): ?Document
    {
        $owned = PrintSampleDocument::query()->where('document_id', $documentId)
            ->whereHas('sample', fn ($q) => $q->where('print_job_id', $job->id))->exists()
            || PrintJobGatePass::query()->where('print_job_id', $job->id)->where('document_id', $documentId)->exists()
            || ($job->getAttribute('bulk_version_id') !== null && BulkVersionFile::query()->where('bulk_version_id', $job->getAttribute('bulk_version_id'))->where('document_id', $documentId)->exists())
            || DesignVersionFile::query()->where('design_version_id', $job->getAttribute('design_version_id'))->where('document_id', $documentId)->exists();

        return $owned ? Document::query()->find($documentId) : null;
    }
}
