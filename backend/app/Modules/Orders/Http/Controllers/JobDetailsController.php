<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\SaveJobDetails;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Additional order information after payment approval (multipart POST, because of the attachment). */
final class JobDetailsController
{
    public function update(Request $request, Order $order, SaveJobDetails $action): OrderResource
    {
        Gate::authorize('manageJobDetails', $order);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'material_id' => ['sometimes', 'nullable', 'integer', Rule::exists('materials', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'material_details' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'design_instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'custom_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'overall_remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'attachment' => ['sometimes', 'file', new AllowedDocument(DocumentCategory::DesignInstruction)],
        ], ['material_id.exists' => 'Select an active fabric type.']);

        /** @var User $actor */
        $actor = $request->user();
        $order = $action->save($actor, $order, $data, $request->file('attachment'));

        return new OrderResource(OrderController::loadDetail($order));
    }

    public function submit(Request $request, Order $order, SaveJobDetails $action): OrderResource
    {
        Gate::authorize('submitJobDetails', $order);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        /** @var User $actor */
        $actor = $request->user();

        return new OrderResource(OrderController::loadDetail($action->submit($actor, $order, (int) $data['version'])));
    }

    /** Signed short-lived URL + order visibility; designers of the order's task may also open it. */
    public function attachment(Request $request, Order $order): StreamedResponse
    {
        $task = $order->designTask()->first();
        if (! Gate::allows('view', $order) && ($task === null || ! Gate::allows('view', $task))) {
            Gate::authorize('view', $order); // throws the correct 403/404
        }
        $document = Document::query()->find($order->getAttribute('design_attachment_document_id')) ?? abort(404);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }
}
