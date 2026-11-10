<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Modules\Organizations\Enums\PagePlatform;
use App\Modules\Organizations\Models\Page;
use App\Support\Validation\PhoneNumber;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePageRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->isMethod('POST') ? Gate::inspect('create', Page::class) : Gate::inspect('update', $this->route('page'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            // Code and invoice prefix are references (invoice numbers): set once, never changed.
            'code' => $creating ? ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('pages', 'code')] : ['prohibited'],
            'invoice_prefix' => $creating ? ['required', 'string', 'min:2', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('pages', 'invoice_prefix')] : ['prohibited'],
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'platform' => [$required, Rule::enum(PagePlatform::class)],
            'external_url' => ['sometimes', 'nullable', 'string', 'url:https,http', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:191'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'currency' => ['sometimes', 'string', Rule::in(['LKR'])],
            'default_advance_percent' => ['sometimes', 'integer', 'between:30,100'],
            'default_delivery_fee_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'invoice_header_name' => [$required, 'string', 'min:2', 'max:150'],
            'invoice_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'invoice_phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'invoice_prefix'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtoupper(trim($this->input($key)))]);
            }
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters, numbers, dashes and underscores.',
            'invoice_prefix.regex' => 'Use capital letters and numbers only.',
            'default_advance_percent.between' => 'The advance must be between 30% and 100%.',
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $data = array_intersect_key($this->validated(), array_flip([
            'code', 'invoice_prefix', 'name', 'platform', 'external_url', 'contact_email', 'address',
            'currency', 'default_advance_percent', 'default_delivery_fee_minor', 'invoice_header_name', 'invoice_address', 'is_active',
        ]));
        foreach (['contact_phone' => 'contact_phone_e164', 'invoice_phone' => 'invoice_phone_e164'] as $input => $column) {
            if ($this->has($input)) {
                $data[$column] = PhoneNumber::normalize($this->validated($input));
            }
        }

        return $data;
    }
}
