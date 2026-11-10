<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Audit entry for activity feeds. Values were redacted when written (AuditLogger::redact).
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'id' => $log->getKey(),
            'occurred_at' => $log->getAttribute('occurred_at')?->toIso8601ZuluString(),
            'action' => $log->getAttribute('action'),
            'actor' => $log->getAttribute('actor_id') === null ? null : [
                'id' => (int) $log->getAttribute('actor_id'),
                'name' => $log->relationLoaded('actor') ? $log->getRelation('actor')?->getAttribute('name') : null,
            ],
            'subject_type' => $log->getAttribute('subject_type'),
            'subject_id' => $log->getAttribute('subject_id') === null ? null : (int) $log->getAttribute('subject_id'),
            'ip' => $log->ipAddress(),
            'request_id' => $log->getAttribute('request_id'),
            'changes' => $log->getAttribute('changes'),
        ];
    }
}
