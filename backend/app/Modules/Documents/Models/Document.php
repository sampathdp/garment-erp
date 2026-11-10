<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Enums\ScanStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['uuid', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'category', 'branch_id', 'uploaded_by', 'scan_status'];

    /** @var list<string> */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'category' => DocumentCategory::class,
            'scan_status' => ScanStatus::class,
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
