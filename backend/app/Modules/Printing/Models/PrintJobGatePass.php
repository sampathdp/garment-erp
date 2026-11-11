<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gate pass details recorded by the Operation Admin after physical printing: number, date,
 * optional scanned document and who recorded it. Append-only (never edited or deleted). The
 * full gate pass workflow (approval, check-out, returns) belongs to a later phase.
 *
 * @property Carbon $gate_pass_date
 */
class PrintJobGatePass extends Model
{
    /** @var list<string> */
    protected $fillable = ['print_job_id', 'gate_pass_no', 'gate_pass_date', 'document_id', 'notes'];

    protected function casts(): array
    {
        return ['gate_pass_date' => 'date'];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function printJob(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
