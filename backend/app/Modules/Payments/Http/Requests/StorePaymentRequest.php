<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Support\Time\BusinessDate;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** multipart/form-data: payment fields + `evidence` file. Status and verifier fields are prohibited. */
final class StorePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('recordPayment', $this->route('order'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $evidenceRequired = in_array(PaymentMethod::tryFrom((string) $this->input('method')), Payment::EVIDENCE_REQUIRED, true);

        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000000'],
            // Cash on delivery is recorded by delivery staff with the delivery (later phase).
            'method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Cod])],
            'reference_no' => [Rule::requiredIf(in_array($this->input('method'), [PaymentMethod::BankTransfer->value, PaymentMethod::Online->value, PaymentMethod::Cheque->value], true)), 'nullable', 'string', 'max:80'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString(), 'after_or_equal:'.BusinessDate::today()->subYear()->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'evidence' => [$evidenceRequired ? 'required' : 'nullable', 'file', new AllowedDocument(DocumentCategory::PaymentSlip)],
            'status' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'recorded_by' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'evidence.required' => 'Attach the payment slip or screenshot.',
            'payment_date.before_or_equal' => 'The payment date cannot be in the future.',
            'reference_no.required' => 'Enter the bank or transaction reference.',
            '*.prohibited' => 'This field is set by the system and cannot be sent.',
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? strtolower($key) : null;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');
        if (is_string($key) && $key !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) !== 1) {
            $this->merge(['idempotency_key_invalid' => true]);
        }
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->boolean('idempotency_key_invalid')) {
                $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be a UUID.');
            }
        }];
    }
}
