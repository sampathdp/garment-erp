<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UrgentDecisionRequest extends FormRequest
{
    public function authorize(): Response
    {
        $slot = UrgentApprovalSlot::tryFrom((string) $this->input('slot'));

        // Unknown slot: check visibility only, then fail validation (422).
        return $slot === null ? Gate::inspect('view', $this->route('order')) : Gate::inspect('decideUrgent', [$this->route('order'), $slot]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slot' => ['required', Rule::enum(UrgentApprovalSlot::class)],
            'decision' => ['required', Rule::enum(UrgentDecision::class)],
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required_if' => 'Give a reason for rejecting the urgent job.'];
    }
}
