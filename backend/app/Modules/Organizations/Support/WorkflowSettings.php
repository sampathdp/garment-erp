<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Support;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\AppSetting;
use Illuminate\Support\Facades\DB;

/**
 * Configurable workflow settings (Super Admin, permission settings.manage). No personal names are
 * hard-coded: the responsible designers are user ids chosen in the settings screen.
 */
final class WorkflowSettings
{
    public const DEFAULT_DESIGNER = 'design.default_designer_id';

    public const DEFAULT_BULK_DESIGNER = 'bulk.default_designer_id';

    public const MAX_CORRECTION_ROUNDS = 'design.max_correction_rounds';

    /** Client mentioned up to three correction rounds. */
    public const DEFAULT_MAX_CORRECTION_ROUNDS = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    public function defaultDesignerId(): ?int
    {
        return $this->int(self::DEFAULT_DESIGNER);
    }

    public function defaultBulkDesignerId(): ?int
    {
        return $this->int(self::DEFAULT_BULK_DESIGNER);
    }

    public function maxCorrectionRounds(): int
    {
        return $this->int(self::MAX_CORRECTION_ROUNDS) ?? self::DEFAULT_MAX_CORRECTION_ROUNDS;
    }

    /** @return array{default_designer_id: int|null, default_bulk_designer_id: int|null, max_correction_rounds: int} */
    public function all(): array
    {
        return [
            'default_designer_id' => $this->defaultDesignerId(),
            'default_bulk_designer_id' => $this->defaultBulkDesignerId(),
            'max_correction_rounds' => $this->maxCorrectionRounds(),
        ];
    }

    /** @param  array<string, int|null>  $values  setting key => value */
    public function update(User $actor, array $values): void
    {
        DB::transaction(function () use ($actor, $values): void {
            $before = [];
            foreach ($values as $key => $value) {
                $before[$key] = AppSetting::query()->whereKey($key)->value('value');
                AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value, 'updated_by' => $actor->id]);
            }
            $this->audit->record('settings.updated', null, ['before' => $before, 'after' => $values], actor: $actor);
        });
    }

    private function int(string $key): ?int
    {
        $value = AppSetting::query()->whereKey($key)->value('value');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
