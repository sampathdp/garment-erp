<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Enums\BulkFileKind;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\Design\Rules\DesignFile;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a new immutable bulk version with its files. Files are stored BEFORE the transaction
 * (no file I/O inside it) and removed again if the transaction fails.
 */
final class BulkUploads
{
    public function __construct(private readonly StoreDocument $documents, private readonly BulkWorkflow $workflow) {}

    /**
     * @param  list<UploadedFile>  $files  PDF / CDR
     * @param  array{external_url?: string|null, transfer_url?: string|null, notes?: string|null}  $meta
     * @param  callable(BulkJob, Order): void  $before  state checks, with the order and job locked
     * @param  callable(BulkVersion, BulkJob, Order): void  $after  runs after the version is written
     */
    public function createVersion(User $actor, BulkJob $job, int $expectedVersion, ?UploadedFile $preview, array $files, array $meta, bool $final, callable $before, callable $after): BulkVersion
    {
        $stored = [];
        try {
            if ($preview !== null) {
                $stored[] = ['kind' => BulkFileKind::Preview, 'category' => DocumentCategory::BulkPreview, 'file' => $this->documents->put($preview, DocumentCategory::BulkPreview, DesignFile::detect($preview, DesignFile::PREVIEW))];
            }
            foreach ($files as $file) {
                $stored[] = ['kind' => BulkFileKind::File, 'category' => DocumentCategory::BulkFile, 'file' => $this->documents->put($file, DocumentCategory::BulkFile, DesignFile::detect($file, DesignFile::SOURCE))];
            }

            return DB::transaction(function () use ($actor, $job, $expectedVersion, $stored, $meta, $final, $before, $after): BulkVersion {
                [$job, $order] = $this->workflow->lock($job, $expectedVersion);
                $before($job, $order);

                $version = new BulkVersion(['bulk_job_id' => $job->id, 'notes' => $meta['notes'] ?? null]);
                $version->forceFill([
                    'version_no' => (int) BulkVersion::query()->where('bulk_job_id', $job->id)->max('version_no') + 1,
                    'previous_version_id' => $job->getAttribute('current_version_id'),
                    'garment_list_version_id' => $job->getAttribute('garment_list_version_id'),
                    'is_final' => $final,
                    'external_url' => $meta['external_url'] ?? null,
                    'transfer_url' => $meta['transfer_url'] ?? null,
                    'submitted_by' => $actor->id,
                    'submitted_at' => now(),
                ])->save();

                foreach ($stored as $entry) {
                    $document = $this->documents->record($entry['file'], $entry['category'], $actor, $order->branch_id);
                    BulkVersionFile::query()->create(['bulk_version_id' => $version->id, 'document_id' => $document->id, 'kind' => $entry['kind']]);
                }

                $job->forceFill(['current_version_id' => $version->id]);
                $after($version, $job, $order);
                $this->workflow->save($job);

                return $version;
            });
        } catch (Throwable $e) {
            foreach ($stored as $entry) {
                $this->documents->discard($entry['file']);
            }
            throw $e;
        }
    }

    /**
     * Stores a single evidence file (image / PDF) outside the transaction.
     *
     * @return array{disk: string, path: string, uuid: string, mime: string, size: int, sha256: string, name: string}
     */
    public function putEvidence(UploadedFile $file): array
    {
        return $this->documents->put($file, DocumentCategory::Evidence);
    }

    /** @param  array{disk: string, path: string, uuid: string, mime: string, size: int, sha256: string, name: string}  $stored */
    public function recordEvidence(array $stored, User $actor, int $branchId): Document
    {
        return $this->documents->record($stored, DocumentCategory::Evidence, $actor, $branchId);
    }

    /** @param  array{disk: string, path: string}  $stored */
    public function discard(array $stored): void
    {
        $this->documents->discard($stored);
    }
}
