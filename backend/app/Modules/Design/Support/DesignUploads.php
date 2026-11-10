<?php

declare(strict_types=1);

namespace App\Modules\Design\Support;

use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Design\Rules\DesignFile;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a new immutable design version with its files and/or shared link.
 * Files are stored BEFORE the transaction and removed again if it fails.
 */
final class DesignUploads
{
    public function __construct(private readonly StoreDocument $documents) {}

    /**
     * @param  list<UploadedFile>  $sources  PDF / CDR
     * @param  callable(DesignTask): void  $before  runs in the transaction with the task locked (state checks)
     * @param  callable(DesignVersion, DesignTask): void  $after  runs in the transaction after the version is written
     */
    public function createVersion(User $actor, DesignTask $task, ?UploadedFile $preview, array $sources, ?string $externalUrl, ?string $notes, bool $final, callable $before, callable $after): DesignVersion
    {
        $stored = [];
        try {
            if ($preview !== null) {
                $stored[] = ['kind' => DesignFileKind::Preview, 'category' => DocumentCategory::DesignPreview, 'file' => $this->documents->put($preview, DocumentCategory::DesignPreview, DesignFile::detect($preview, DesignFile::PREVIEW))];
            }
            foreach ($sources as $source) {
                $stored[] = ['kind' => DesignFileKind::Source, 'category' => DocumentCategory::DesignSource, 'file' => $this->documents->put($source, DocumentCategory::DesignSource, DesignFile::detect($source, DesignFile::SOURCE))];
            }

            return DB::transaction(function () use ($actor, $task, $stored, $externalUrl, $notes, $final, $before, $after): DesignVersion {
                $task = DesignTask::query()->lockForUpdate()->findOrFail($task->id);
                $before($task);

                $branchId = (int) DB::table('orders')->where('id', $task->order_id)->value('branch_id');
                $version = new DesignVersion(['design_task_id' => $task->id, 'notes' => $notes]);
                $version->forceFill([
                    'version_no' => (int) DesignVersion::query()->where('design_task_id', $task->id)->max('version_no') + 1,
                    'previous_version_id' => $task->getAttribute('current_version_id'),
                    'external_url' => $externalUrl,
                    'is_final' => $final,
                    'created_by' => $actor->id,
                    'submitted_at' => now(),
                ])->save();

                foreach ($stored as $entry) {
                    $document = $this->documents->record($entry['file'], $entry['category'], $actor, $branchId);
                    DesignVersionFile::query()->create(['design_version_id' => $version->id, 'document_id' => $document->id, 'kind' => $entry['kind']]);
                }

                $task->forceFill(['current_version_id' => $version->id, 'version' => $task->version + 1])->save();
                $after($version, $task);

                return $version;
            });
        } catch (Throwable $e) {
            foreach ($stored as $entry) {
                $this->documents->discard($entry['file']);
            }
            throw $e;
        }
    }
}
