<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only status history of a printing job (written by PrintingWorkflow::transition()).
 *
 * @property PrintJobStatus|null $from_status
 * @property PrintJobStatus $to_status
 */
class PrintJobEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['print_job_id', 'from_status', 'to_status', 'note', 'actor_id'];

    protected function casts(): array
    {
        return [
            'from_status' => PrintJobStatus::class,
            'to_status' => PrintJobStatus::class,
        ];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
