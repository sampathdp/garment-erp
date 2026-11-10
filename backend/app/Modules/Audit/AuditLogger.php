<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Support\Http\RequestId;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Single entry point for audit records (WORKFLOW_REQUIREMENTS.md §3.14).
 *
 * Call inside the same DB transaction as the change it records, so the audit
 * row and the change commit or roll back together.
 */
final class AuditLogger
{
    /** Keys whose values are never written to the audit log. */
    private const REDACTED_KEYS = ['password', 'password_confirmation', 'current_password', 'remember_token', 'token', 'secret', 'api_key'];

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>|null  $changes  e.g. ['before' => [...], 'after' => [...]]
     */
    public function record(string $action, ?Model $subject = null, ?array $changes = null, ?User $actor = null, ?int $branchId = null): AuditLog
    {
        $actor ??= $this->auth->guard()->user();
        if ($branchId === null && $subject !== null && array_key_exists('branch_id', $subject->getAttributes())) {
            $branchId = $subject->getAttribute('branch_id');
        }
        $ip = $this->request->ip();

        return AuditLog::query()->create([
            'occurred_at' => now(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'branch_id' => $branchId === null ? null : (int) $branchId,
            'ip' => $ip !== null ? (@inet_pton($ip) ?: null) : null,
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'request_id' => RequestId::current($this->request),
            'changes' => $changes === null ? null : self::redact($changes),
        ]);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
