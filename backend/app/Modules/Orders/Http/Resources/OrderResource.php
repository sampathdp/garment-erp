<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Order ("job"). List rows and the detail share this shape; the detail adds urgent-approval
 * slots, status history and abilities. Every figure is calculated on the server.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('orders.index');
        /** @var OrderItem|null $item */
        $item = $this->relationLoaded('items') ? $this->items->first() : null;
        $approval = $this->relationLoaded('currentUrgentApproval') ? $this->currentUrgentApproval : null;
        /** @var User|null $user */
        $user = $request->user();

        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'page' => $this->whenLoaded('page', fn () => ['id' => $this->page->id, 'code' => $this->page->code, 'name' => $this->page->name]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id, 'customer_code' => $this->customer->customer_code, 'name' => $this->customer->name,
                'phone' => $this->customer->phone_e164, 'whatsapp' => $this->customer->whatsapp_e164,
                'other_phone' => $this->customer->alt_phone_e164, 'address' => $this->customer->address_line1,
            ]),
            'order_type' => $this->whenLoaded('orderType', fn () => $this->orderType ? ['id' => $this->orderType->id, 'name' => $this->orderType->name] : null),
            'product_category' => $item?->relationLoaded('productType') ? ['id' => $item->productType->id, 'name' => $item->productType->name] : null,
            'gross_quantity' => $this->total_quantity,
            'due_date' => $this->required_delivery_date->format('Y-m-d'),
            'currency' => $this->currency,
            'pricing' => [
                'unit_price_minor' => $item?->unit_price_minor,
                'subtotal_minor' => $this->subtotal_minor,
                'discount_minor' => $this->discount_minor,
                'tax_minor' => $this->tax_minor,
                'delivery_fee_minor' => $this->delivery_fee_minor,
                'total_minor' => $this->total_minor,
            ],
            'advance' => [
                'percent' => $this->advance_percent,
                'required_minor' => $this->advance_required_minor,
                'verified_minor' => $this->verified_paid_minor,
                'shortfall_minor' => $this->advanceShortfallMinor(),
                'meets_requirement' => $this->meetsAdvanceRequirement(),
            ],
            'payment_status' => $this->payment_status->value,
            'production_eligible' => $this->isProductionEligible(),
            'urgent' => [
                'is_urgent' => $this->is_urgent,
                'status' => $this->urgent_status->value,
                'approvals_given' => $approval?->relationLoaded('decisions') ? $approval->decisions->where('decision.value', 'approved')->count() : null,
                'approvals_required' => $this->is_urgent ? count(UrgentApprovalSlot::cases()) : 0,
            ],
            'approval_status' => $this->approvalStatus($approval?->relationLoaded('decisions') ? $approval->decisions->where('decision.value', 'approved')->count() : 0),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'version' => $this->version,
            'urgent_approval' => $this->when($detail && $approval !== null, fn (): array => [
                'id' => $approval->id,
                'days_available' => $approval->working_days_available,
                'reference_date' => $approval->reference_date?->format('Y-m-d'),
                'due_date' => $approval->evaluated_delivery_date->format('Y-m-d'),
                'requested_at' => $approval->requested_at?->toIso8601ZuluString(),
                'decision' => $approval->decision?->value,
                'slots' => array_map(function (UrgentApprovalSlot $slot) use ($approval): array {
                    /** @var UrgentApprovalDecision|null $d */
                    $d = $approval->decisions->first(fn (UrgentApprovalDecision $x) => $x->slot === $slot);

                    return [
                        'slot' => $slot->value, 'label' => $slot->label(),
                        'decision' => $d?->decision->value, 'reason' => $d?->reason,
                        'decided_by' => $d && $d->relationLoaded('decider') ? ['id' => $d->decider->id, 'name' => $d->decider->name] : null,
                        'decided_at' => $d?->decided_at?->toIso8601ZuluString(),
                    ];
                }, UrgentApprovalSlot::cases()),
            ]),
            // Additional order information: only from payment approval onwards (never before the stage).
            'job_details' => $this->when($detail && $this->status->isReleased() && $this->relationLoaded('material'), fn (): array => [
                'material' => $this->material ? ['id' => $this->material->id, 'name' => $this->material->name] : null,
                'material_details' => $this->material_details,
                'design_instructions' => $this->design_instructions,
                'custom_notes' => $this->custom_notes,
                'overall_remarks' => $this->overall_remarks,
                'attachment' => $this->designAttachment ? [
                    'name' => $this->designAttachment->original_name,
                    'mime_type' => $this->designAttachment->mime_type,
                    'url' => URL::temporarySignedRoute('orders.job-details.attachment', now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5)), ['order' => $this->id]),
                ] : null,
                'submitted_at' => $this->details_submitted_at?->toIso8601ZuluString(),
            ]),
            'design_task' => $this->when($this->relationLoaded('designTask'), fn () => $this->designTask ? [
                'id' => $this->designTask->id,
                'status' => ['value' => $this->designTask->status->value, 'label' => $this->designTask->status->label()],
                'designer' => $this->designTask->relationLoaded('designer') && $this->designTask->designer ? ['id' => $this->designTask->designer->id, 'name' => $this->designTask->designer->name] : null,
                'correction_rounds' => $this->designTask->correction_rounds,
            ] : null),
            'bulk' => $this->when($detail && $this->status->isReleased() && $this->relationLoaded('bulkJob'), fn (): array => [
                'job' => $this->bulkJob ? ['id' => $this->bulkJob->id, 'status' => $this->bulkJob->status->value] : null,
                'checklist' => app(CreateBulkJobIfEligible::class)->checklist($this->resource),
            ]),
            'history' => $this->when($detail && $this->relationLoaded('statusHistories'), fn () => $this->statusHistories->map(fn (OrderStatusHistory $h): array => [
                'from' => $h->from_status?->label(), 'to' => $h->to_status->label(), 'reason' => $h->reason,
                'actor' => $h->relationLoaded('actor') && $h->actor ? $h->actor->name : null,
                'at' => $h->created_at?->toIso8601ZuluString(),
            ])->all()),
            'abilities' => $this->when($detail && $user !== null, fn (): array => [
                'update' => Gate::allows('update', $this->resource),
                'submit' => Gate::allows('submit', $this->resource),
                'manage_job_details' => Gate::allows('manageJobDetails', $this->resource),
                'submit_job_details' => Gate::allows('submitJobDetails', $this->resource),
                'record_payment' => Gate::allows('recordPayment', $this->resource),
                'view_payments' => Gate::allows('viewPayments', $this->resource),
                ...collect(UrgentApprovalSlot::cases())->mapWithKeys(fn (UrgentApprovalSlot $slot): array => [
                    'decide_urgent_'.$slot->value => Gate::allows('decideUrgent', [$this->resource, $slot])
                        && $approval !== null && $approval->isOpen()
                        && ! $approval->decisions->contains(fn (UrgentApprovalDecision $d) => $d->slot === $slot || $d->decided_by === $user?->id),
                ])->all(),
            ]),
        ];
    }

    private function approvalStatus(int $given): string
    {
        return match ($this->urgent_status) {
            UrgentStatus::NotRequired => 'Not required',
            UrgentStatus::Pending => "Awaiting urgent approval ({$given} of ".count(UrgentApprovalSlot::cases()).')',
            UrgentStatus::Approved => 'Urgent approved',
            UrgentStatus::Rejected => 'Urgent rejected',
        };
    }
}
