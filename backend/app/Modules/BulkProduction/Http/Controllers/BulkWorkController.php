<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Controllers;

use App\Modules\BulkProduction\Actions\CompleteBulkJob;
use App\Modules\BulkProduction\Actions\RecordBulkMeasurements;
use App\Modules\BulkProduction\Actions\ReviewBulk;
use App\Modules\BulkProduction\Actions\SubmitBulkForReview;
use App\Modules\BulkProduction\Actions\UpdateBulkChecklist;
use App\Modules\BulkProduction\Http\Resources\BulkJobResource;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Design\Rules\DesignFile;
use App\Modules\Design\Rules\ExternalDesignLink;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Bulk work endpoints. Each one: policy (scope → assignment → permission → state) → validation →
 * one Action (which re-checks the state under the row lock) → the refreshed job.
 */
final class BulkWorkController
{
    public function measurements(Request $request, BulkJob $job, RecordBulkMeasurements $action): BulkJobResource
    {
        Gate::authorize('enterMeasurements', $job);
        $data = $request->validate(['values' => ['required', 'array', 'min:1', 'max:50']]);
        $action->record($this->actor($request), $job, $data['values']);

        return $this->fresh($job);
    }

    /** Administrative correction after completion (bulk.correct_measurements, reason required, until printing starts). */
    public function correctMeasurements(Request $request, BulkJob $job, RecordBulkMeasurements $action): BulkJobResource
    {
        Gate::authorize('correctMeasurements', $job);
        $data = $request->validate(['values' => ['required', 'array', 'min:1', 'max:50'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $action->correct($this->actor($request), $job, $data['values'], $data['reason']);

        return $this->fresh($job);
    }

    public function checklistItem(Request $request, BulkJob $job, int $item, UpdateBulkChecklist $action): BulkJobResource
    {
        Gate::authorize('updateChecklist', $job);
        $data = $request->validate([
            'is_completed' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (! array_key_exists('is_completed', $data) && ! array_key_exists('notes', $data)) {
            throw new ApiException(422, 'validation_failed', 'Nothing to update.', ['is_completed' => ['Send is_completed and/or notes.']]);
        }
        $action->updateItem($this->actor($request), $job, $item, array_key_exists('is_completed', $data) ? (bool) $data['is_completed'] : null,
            $data['notes'] ?? null, array_key_exists('notes', $data));

        return $this->fresh($job);
    }

    /** The Page Owner records the client's confirmation of the final checklist (customers do not log in). */
    public function confirmChecklist(Request $request, BulkJob $job, UpdateBulkChecklist $action): BulkJobResource
    {
        Gate::authorize('confirmChecklist', $job);
        $data = $request->validate([
            'channel' => ['required', Rule::enum(ApprovalChannel::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'evidence' => ['sometimes', 'file', new AllowedDocument(DocumentCategory::Evidence)],
        ]);
        $action->confirmWithClient($this->actor($request), $job, ApprovalChannel::from($data['channel']), $data['comment'] ?? null, $request->file('evidence'));

        return $this->fresh($job);
    }

    /** Review submission: preview image and/or PDF/CDR layout files (at least one file), optional shared link. */
    public function submitForReview(Request $request, BulkJob $job, SubmitBulkForReview $action): BulkJobResource
    {
        Gate::authorize('submitForReview', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'preview' => ['sometimes', 'file', new DesignFile(DesignFile::PREVIEW)],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['file', new DesignFile(DesignFile::SOURCE)],
            'external_url' => ['sometimes', 'nullable', 'string', new ExternalDesignLink],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (! $request->hasFile('preview') && ! $request->hasFile('files')) {
            throw new ApiException(422, 'validation_failed', 'Upload the bulk review file(s).', ['files' => ['Upload a preview image or a PDF/CDR layout file.']]);
        }
        $action->handle($this->actor($request), $job, (int) $data['version'], $request->file('preview'), array_values($request->file('files', [])), $data['external_url'] ?? null, $data['notes'] ?? null);

        return $this->fresh($job);
    }

    public function requestCorrection(Request $request, BulkJob $job, ReviewBulk $action): BulkJobResource
    {
        Gate::authorize('review', $job);
        $data = $request->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->requestCorrection($this->actor($request), $job, (int) $data['version'], $data['reason']);

        return $this->fresh($job);
    }

    public function approve(Request $request, BulkJob $job, ReviewBulk $action): BulkJobResource
    {
        Gate::authorize('review', $job);
        $data = $request->validate(['version' => ['required', 'integer'], 'comment' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $action->approve($this->actor($request), $job, (int) $data['version'], $data['comment'] ?? null);

        return $this->fresh($job);
    }

    /** Final internal submission: final bulk file(s) AND the WeTransfer link; completes the task. */
    public function submitFinal(Request $request, BulkJob $job, CompleteBulkJob $action): BulkJobResource
    {
        Gate::authorize('submitFinal', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'preview' => ['sometimes', 'file', new DesignFile(DesignFile::PREVIEW)],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['file', new DesignFile(DesignFile::SOURCE)],
            'transfer_url' => ['required', 'string', new ExternalDesignLink('documents.bulk_transfer_link_hosts')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $action->internal($this->actor($request), $job, (int) $data['version'], $request->file('preview'), array_values($request->file('files', [])), $data['transfer_url'], $data['notes'] ?? null);

        return $this->fresh($job);
    }

    public function addEvidence(Request $request, BulkJob $job, CompleteBulkJob $action): BulkJobResource
    {
        Gate::authorize('manageExternal', $job);
        $data = $request->validate([
            'file' => ['required', 'file', new AllowedDocument(DocumentCategory::Evidence)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $action->addEvidence($this->actor($request), $job, $file, $data['note'] ?? null);

        return $this->fresh($job);
    }

    /** External completion recorded by the Operation Admin (no designer completion, no WeTransfer link). */
    public function completeExternal(Request $request, BulkJob $job, CompleteBulkJob $action): BulkJobResource
    {
        Gate::authorize('manageExternal', $job);
        $data = $request->validate(['version' => ['required', 'integer'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $action->external($this->actor($request), $job, (int) $data['version'], $data['notes'] ?? null);

        return $this->fresh($job);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function fresh(BulkJob $job): BulkJobResource
    {
        return new BulkJobResource(BulkJobController::loadDetail($job->refresh()));
    }
}
