<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Queries;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobDocument;
use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Documents\Models\Document;

/**
 * Files a bulk job may serve: its own version files and evidence, plus the files of the APPROVED
 * and FINAL design versions (bulk designers have no access to the design task itself). Anything
 * else is a 404, even for a valid document id.
 */
final class BulkDocuments
{
    public function find(BulkJob $job, int $documentId): ?Document
    {
        $owned = BulkVersionFile::query()->where('document_id', $documentId)
            ->whereHas('bulkVersion', fn ($q) => $q->where('bulk_job_id', $job->id))->exists()
            || BulkJobDocument::query()->where('bulk_job_id', $job->id)->where('document_id', $documentId)->exists();

        if (! $owned) {
            $task = DesignTask::query()->select(['id', 'approved_version_id', 'final_version_id'])->find($job->getAttribute('design_task_id'));
            $versionIds = array_values(array_filter([$task?->getAttribute('approved_version_id'), $task?->getAttribute('final_version_id')]));
            $owned = $versionIds !== [] && DesignVersionFile::query()->where('document_id', $documentId)->whereIn('design_version_id', $versionIds)->exists();
        }

        return $owned ? Document::query()->find($documentId) : null;
    }
}
