<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Confirm (`notes` optional) or reject (`reason` required) a payment. */
final class DecidePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('verify', $this->route('payment'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->routeIs('payments.reject')
            ? ['reason' => ['required', 'string', 'min:3', 'max:500']]
            : ['notes' => ['sometimes', 'nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Give the reason for rejecting this payment.'];
    }
}
