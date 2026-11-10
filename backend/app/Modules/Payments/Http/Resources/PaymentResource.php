<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentVerificationEvent;
use App\Support\Time\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/** @mixin Payment */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $request->routeIs('payments.show', 'payments.store', 'payments.verify', 'payments.reject');
        $order = $this->relationLoaded('order') ? $this->order : null;

        return [
            'id' => $this->id,
            'order' => $order === null ? null : [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'status' => ['value' => $order->status->value, 'label' => $order->status->label()],
                'customer' => $order->relationLoaded('customer') ? ['id' => $order->customer->id, 'name' => $order->customer->name] : null,
                'page' => $order->relationLoaded('page') ? ['id' => $order->page->id, 'code' => $order->page->code, 'name' => $order->page->name] : null,
                'total_minor' => $order->total_minor,
                'advance_required_minor' => $order->advance_required_minor,
                'verified_minor' => $order->verified_paid_minor,
                'shortfall_minor' => $order->advanceShortfallMinor(),
                'meets_requirement' => $order->meetsAdvanceRequirement(),
            ],
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'reference_no' => $this->reference_no,
            'payment_date' => BusinessDate::of($this->paid_at)->toDateString(),
            'status' => $this->status->value,
            'recorded_by' => $this->relationLoaded('recorder') ? ['id' => $this->recorder->id, 'name' => $this->recorder->name] : null,
            'recorded_at' => $this->created_at?->toIso8601ZuluString(),
            'verified_by' => $this->relationLoaded('verifier') && $this->verifier ? ['id' => $this->verifier->id, 'name' => $this->verifier->name] : null,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'verification_notes' => $this->verification_notes,
            'rejection_reason' => $this->rejection_reason,
            'evidence' => $this->when($detail, fn () => $this->slip_document_id === null || ! $this->relationLoaded('slip') || $this->slip === null ? null : [
                'name' => $this->slip->original_name,
                'mime_type' => $this->slip->mime_type,
                'size_bytes' => $this->slip->size_bytes,
                // Short-lived signed URL; the route also re-checks the payment policy.
                'url' => URL::temporarySignedRoute('payments.evidence', now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5)), ['payment' => $this->id]),
            ]),
            'history' => $this->when($detail && $this->relationLoaded('verificationEvents'), fn () => $this->verificationEvents->map(fn (PaymentVerificationEvent $e): array => [
                'action' => $e->action->value,
                'notes' => $e->notes,
                'actor' => $e->relationLoaded('actor') ? $e->actor->name : null,
                'at' => $e->created_at?->toIso8601ZuluString(),
            ])->all()),
            'abilities' => $this->when($detail, fn (): array => [
                'verify' => Gate::allows('verify', $this->resource),
                'reject' => Gate::allows('verify', $this->resource),
            ]),
        ];
    }
}
