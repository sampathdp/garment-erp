<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Enums\ScanStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an uploaded file (validated beforehand with AllowedDocument) on the private disk under
 * a UUID path. The client file name is metadata only and never part of the path.
 *
 * Usage: call `put()` BEFORE the owning transaction (no file I/O inside transactions), then
 * `record()` inside it. If the transaction fails, call `discard()` to remove the orphan file.
 */
final class StoreDocument
{
    /** @return array{disk: string, path: string, uuid: string, mime: string, size: int, sha256: string, name: string} */
    public function put(UploadedFile $file, DocumentCategory $category, ?string $detectedMime = null): array
    {
        $disk = (string) config('documents.disk', 'private');
        $uuid = (string) Str::uuid();
        $directory = $category->value.'/'.now()->format('Y/m');
        $mime = $detectedMime ?? ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream');

        Storage::disk($disk)->putFileAs($directory, $file, $uuid);

        return [
            'disk' => $disk,
            'path' => $directory.'/'.$uuid,
            'uuid' => $uuid,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', $file->getRealPath()),
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
        ];
    }

    /** @param  array{disk: string, path: string, uuid: string, mime: string, size: int, sha256: string, name: string}  $stored */
    public function record(array $stored, DocumentCategory $category, User $uploader, ?int $branchId): Document
    {
        return Document::query()->create([
            'uuid' => $stored['uuid'],
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'original_name' => $stored['name'],
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'category' => $category,
            'branch_id' => $branchId,
            'uploaded_by' => $uploader->id,
            'scan_status' => ScanStatus::Skipped,
        ]);
    }

    /** @param  array{disk: string, path: string}  $stored */
    public function discard(array $stored): void
    {
        try {
            Storage::disk($stored['disk'])->delete($stored['path']);
        } catch (Throwable) {
            // Best effort: an orphan file without a document row is never served.
        }
    }
}
