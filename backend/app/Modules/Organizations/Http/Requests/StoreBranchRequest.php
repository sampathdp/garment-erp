<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Modules\Organizations\Models\Branch;
use App\Support\Validation\PhoneNumber;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->isMethod('POST') ? Gate::inspect('create', Branch::class) : Gate::inspect('update', $this->route('branch'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            // Codes are references (documents, numbering): set once, never changed.
            'code' => $creating ? ['required', 'string', 'min:2', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('branches', 'code')] : ['prohibited'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'Use capital letters, numbers, dashes and underscores.'];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $data = array_intersect_key($this->validated(), array_flip(['code', 'name', 'address', 'is_active']));
        if ($this->has('phone')) {
            $data['phone_e164'] = PhoneNumber::normalize($this->validated('phone'));
        }

        return $data;
    }
}
