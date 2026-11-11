<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a production issue (reported, status changes, assignments).
 *
 * @property IssueStatus|null $from_status
 * @property IssueStatus $to_status
 */
class ProductionIssueEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['production_issue_id', 'from_status', 'to_status', 'note', 'actor_id'];

    protected function casts(): array
    {
        return ['from_status' => IssueStatus::class, 'to_status' => IssueStatus::class];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
