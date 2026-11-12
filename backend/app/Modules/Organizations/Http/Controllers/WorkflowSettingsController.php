<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Organizations\Support\WorkflowSettings;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Super Admin configuration: responsible designer, responsible bulk designer, correction limit. */
final class WorkflowSettingsController
{
    public function show(WorkflowSettings $settings, StaffDirectory $staff): JsonResponse
    {
        Gate::authorize('workflow-settings.manage');

        return ApiResponse::data([
            ...$settings->all(),
            'designers' => $staff->designersWithWorkload(),
            'bulk_designers' => $staff->bulkDesignersWithWorkload(),
        ]);
    }

    public function update(Request $request, WorkflowSettings $settings, StaffDirectory $staff): JsonResponse
    {
        Gate::authorize('workflow-settings.manage');
        $eligible = fn (string $permission) => static function (string $attribute, mixed $value, Closure $fail) use ($staff, $permission): void {
            if ($value !== null && $staff->eligible((int) $value, $permission) === null) {
                $fail('Select an active user who holds the required permission.');
            }
        };
        $data = $request->validate([
            'default_designer_id' => ['present', 'nullable', 'integer', $eligible(Permissions::DESIGNS_UPLOAD_VERSION)],
            'default_bulk_designer_id' => ['present', 'nullable', 'integer', $eligible(Permissions::BULK_MEASURE)],
            'auto_assign_designer' => ['sometimes', 'boolean'],
            'auto_assign_bulk_designer' => ['sometimes', 'boolean'],
            'max_correction_rounds' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $payload = [
            WorkflowSettings::DEFAULT_DESIGNER => $data['default_designer_id'] === null ? null : (int) $data['default_designer_id'],
            WorkflowSettings::DEFAULT_BULK_DESIGNER => $data['default_bulk_designer_id'] === null ? null : (int) $data['default_bulk_designer_id'],
            WorkflowSettings::MAX_CORRECTION_ROUNDS => (int) $data['max_correction_rounds'],
        ];
        if (array_key_exists('auto_assign_designer', $data)) {
            $payload[WorkflowSettings::AUTO_ASSIGN_DESIGNER] = (bool) $data['auto_assign_designer'];
        }
        if (array_key_exists('auto_assign_bulk_designer', $data)) {
            $payload[WorkflowSettings::AUTO_ASSIGN_BULK_DESIGNER] = (bool) $data['auto_assign_bulk_designer'];
        }

        $settings->update($actor, $payload);

        return $this->show($settings, $staff);
    }
}
