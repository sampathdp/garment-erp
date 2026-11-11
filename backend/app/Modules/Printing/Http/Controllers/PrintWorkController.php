<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Actions\RecordPrintGatePass;
use App\Modules\Printing\Actions\ReviewPrintSample;
use App\Modules\Printing\Actions\RunPhysicalPrinting;
use App\Modules\Printing\Actions\SendForPrinting;
use App\Modules\Printing\Actions\UploadPrintSample;
use App\Modules\Printing\Http\Resources\PrintJobResource;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Rules\SampleImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Printing workflow endpoints. Each one: policy (scope → permission → state) → validation →
 * one Action (which re-checks the state under the row locks) → the refreshed job.
 */
final class PrintWorkController
{
    public function send(Request $request, PrintJob $job, SendForPrinting $action): PrintJobResource
    {
        Gate::authorize('send', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->handle($this->actor($request), $job, (int) $data['version'], isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            $this->text($data['recipient_name'] ?? null), $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    public function uploadSample(Request $request, PrintJob $job, UploadPrintSample $action): PrintJobResource
    {
        Gate::authorize('uploadSample', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'image' => ['required', 'file', new SampleImage],
            'documents' => ['sometimes', 'array', 'max:5'],
            'documents.*' => ['file', new AllowedDocument(DocumentCategory::SamplePhoto)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var UploadedFile $image */
        $image = $request->file('image');
        /** @var list<UploadedFile> $documents */
        $documents = array_values($request->file('documents', []));
        $action->handle($this->actor($request), $job, (int) $data['version'], $image, $documents, $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    public function approveSample(Request $request, PrintJob $job, ReviewPrintSample $action): PrintJobResource
    {
        Gate::authorize('reviewSample', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'channel' => ['sometimes', 'nullable', Rule::enum(ApprovalChannel::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $action->approve($this->actor($request), $job, (int) $data['version'], isset($data['channel']) ? ApprovalChannel::from($data['channel']) : null, $this->text($data['comment'] ?? null));

        return $this->fresh($job);
    }

    /** A rejection reason is mandatory (422 without one). */
    public function rejectSample(Request $request, PrintJob $job, ReviewPrintSample $action): PrintJobResource
    {
        Gate::authorize('reviewSample', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'channel' => ['sometimes', 'nullable', Rule::enum(ApprovalChannel::class)],
        ]);
        $action->reject($this->actor($request), $job, (int) $data['version'], (string) $data['reason'], isset($data['channel']) ? ApprovalChannel::from($data['channel']) : null);

        return $this->fresh($job);
    }

    public function startPrinting(Request $request, PrintJob $job, RunPhysicalPrinting $action): PrintJobResource
    {
        Gate::authorize('startPrinting', $job);
        $data = $request->validate(['version' => ['required', 'integer'], 'notes' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $action->start($this->actor($request), $job, (int) $data['version'], $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    public function completePrinting(Request $request, PrintJob $job, RunPhysicalPrinting $action): PrintJobResource
    {
        Gate::authorize('completePrinting', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'printed_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->complete($this->actor($request), $job, (int) $data['version'], (int) $data['printed_quantity'], $this->text($data['notes'] ?? null));

        return $this->fresh($job);
    }

    public function recordGatePass(Request $request, PrintJob $job, RecordPrintGatePass $action): PrintJobResource
    {
        Gate::authorize('recordGatePass', $job);
        $data = $request->validate([
            'gate_pass_no' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-_. ]*$/'],
            'gate_pass_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Colombo')->toDateString()],
            'document' => ['sometimes', 'file', new AllowedDocument(DocumentCategory::GatePass)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $action->handle($this->actor($request), $job, trim((string) $data['gate_pass_no']), Carbon::createFromFormat('Y-m-d', (string) $data['gate_pass_date'], 'Asia/Colombo') ?: now(),
            $request->file('document'), $this->text($data['notes'] ?? null));

        return $this->fresh($job);
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

    private function fresh(PrintJob $job): PrintJobResource
    {
        return new PrintJobResource(PrintJobController::loadDetail($job->refresh()));
    }
}
