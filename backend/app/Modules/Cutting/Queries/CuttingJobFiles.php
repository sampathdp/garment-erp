<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Queries;

use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Documents\Models\Document;
use App\Modules\Printing\Models\PrintJob;

/**
 * Files a cutting job may serve: the files of the approved design version it was printed from
 * and the client confirmation evidence of its checklist. Anything else is a 404, even for a
 * valid document id (issue images are served by the issue's own route).
 */
final class CuttingJobFiles
{
    public function find(CuttingJob $job, int $documentId): ?Document
    {
        $designVersionId = PrintJob::query()->whereKey($job->print_job_id)->value('design_version_id');
        $owned = ($designVersionId !== null && DesignVersionFile::query()->where('design_version_id', $designVersionId)->where('document_id', $documentId)->exists())
            || ChecklistRun::query()->where('cutting_job_id', $job->id)->where('client_evidence_document_id', $documentId)->exists();

        return $owned ? Document::query()->find($documentId) : null;
    }
}
