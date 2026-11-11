<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Http\Controllers;

use App\Modules\BulkProduction\Actions\ReportProductionIssue;
use App\Modules\BulkProduction\Enums\ChecklistResult;
use App\Modules\BulkProduction\Http\Controllers\ProductionIssueController;
use App\Modules\BulkProduction\Http\Resources\ProductionIssueResource;
use App\Modules\Cutting\Actions\AssignCuttingJob;
use App\Modules\Cutting\Actions\CompleteCuttingJob;
use App\Modules\Cutting\Actions\RecordCuttingQuantities;
use App\Modules\Cutting\Actions\UpdateCuttingChecklist;
use App\Modules\Cutting\Http\Resources\CuttingJobResource;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Cutting workflow endpoints. Each one: policy (scope → assignment → permission → state) →
 * validation → one Action (which re-checks the state under the row locks) → the refreshed job.
 */
final class CuttingWorkController
{
    public function assign(Request $request, CuttingJob $job, AssignCuttingJob $action): CuttingJobResource
    {
        Gate::authorize('assign', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'cutter_id' => ['required', 'integer'],
            'expected_completion_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Colombo')->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->handle($this->actor($request), $job, (int) $data['version'], (int) $data['cutter_id'],
            Carbon::createFromFormat('Y-m-d', (string) $data['expected_completion_date'], 'Asia/Colombo') ?: now(),
            $this->text($data['notes'] ?? null), $this->text($data['reason'] ?? null));

        return $this->fresh($job);
    }

    /** Cut quantity per size: { version, quantities: [{ id, cut }] }. */
    public function quantities(Request $request, CuttingJob $job, RecordCuttingQuantities $action): CuttingJobResource
    {
        Gate::authorize('recordWork', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'quantities' => ['required', 'array', 'min:1', 'max:200'],
            'quantities.*.id' => ['required', 'integer', 'distinct'],
            'quantities.*.cut' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $cut = [];
        foreach ($data['quantities'] as $row) {
            $cut[(int) $row['id']] = (int) $row['cut'];
        }
        $action->handle($this->actor($request), $job, (int) $data['version'], $cut);

        return $this->fresh($job);
    }

    public function checklistItem(Request $request, CuttingJob $job, int $item, UpdateCuttingChecklist $action): CuttingJobResource
    {
        Gate::authorize('updateChecklist', $job);
        $data = $request->validate([
            'is_completed' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'responsible_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $action->updateItem($this->actor($request), $job, $item, array_key_exists('is_completed', $data) ? (bool) $data['is_completed'] : null,
            $this->text($data['notes'] ?? null), array_key_exists('notes', $data),
            isset($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null, array_key_exists('responsible_user_id', $data));

        return $this->fresh($job);
    }

    public function qualityCheck(Request $request, CuttingJob $job, int $item, UpdateCuttingChecklist $action): CuttingJobResource
    {
        Gate::authorize('recordQualityCheck', $job);
        $data = $request->validate([
            'result' => ['required', Rule::enum(ChecklistResult::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $action->recordQualityCheck($this->actor($request), $job, $item, ChecklistResult::from($data['result']), $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    public function confirmChecklist(Request $request, CuttingJob $job, UpdateCuttingChecklist $action): CuttingJobResource
    {
        Gate::authorize('confirmChecklist', $job);
        $data = $request->validate([
            'channel' => ['required', Rule::enum(ApprovalChannel::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'evidence' => ['sometimes', 'file', new AllowedDocument(DocumentCategory::Evidence)],
        ]);
        /** @var UploadedFile|null $evidence */
        $evidence = $request->file('evidence');
        $action->confirmWithClient($this->actor($request), $job, ApprovalChannel::from($data['channel']), $this->text($data['comment'] ?? null), $evidence);

        return $this->fresh($job);
    }

    public function complete(Request $request, CuttingJob $job, CompleteCuttingJob $action): CuttingJobResource
    {
        Gate::authorize('complete', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->handle($this->actor($request), $job, (int) $data['version'], $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    /** Damaged pieces, incorrect cuts, missing sleeves/parts, missing items … reported from the cutting job. */
    public function reportIssue(Request $request, CuttingJob $job, ReportProductionIssue $action): JsonResponse
    {
        Gate::authorize('reportIssue', $job);
        $data = $request->validate(ProductionIssueController::reportRules());
        $issue = $action->handle($this->actor($request), Order::query()->findOrFail($job->order_id), $job, ProductionIssueController::reportData($data), $request->file('image'));

        return (new ProductionIssueResource(ProductionIssueController::loadDetail($issue)))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    private function fresh(CuttingJob $job): CuttingJobResource
    {
        return new CuttingJobResource(CuttingJobController::loadDetail($job->refresh()));
    }
}
