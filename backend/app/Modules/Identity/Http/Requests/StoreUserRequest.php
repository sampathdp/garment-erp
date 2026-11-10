<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Support\Validation\PhoneNumber;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create (POST) or edit basic details (PATCH) of a user. Authorization runs in the controller. */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): Response
    {
        // Before validation, so unauthorized callers learn nothing (for example which emails exist).
        return $this->isMethod('POST') ? Gate::inspect('create', User::class) : Gate::inspect('update', $this->route('user'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $target = $this->route('user');
        $ignoreId = $target instanceof User ? $target->id : null;

        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            // Unique across soft-deleted accounts too (the DB key covers them).
            'email' => [$creating ? 'required' : 'sometimes', 'string', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($ignoreId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
        ];

        if ($creating) {
            $rules += [
                'roles' => ['present', 'array', 'max:20'],
                'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
                'branch_ids' => ['sometimes', 'array', 'max:100'],
                'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
                'page_ids' => ['sometimes', 'array', 'max:100'],
                'page_ids.*' => ['integer', 'distinct', Rule::exists('pages', 'id')],
                'owner_page_ids' => ['sometimes', 'array', 'max:100'],
                'owner_page_ids.*' => ['integer', 'distinct', 'in_array:page_ids.*'],
                'password' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, string|null> */
    public function details(): array
    {
        $details = [];
        if ($this->has('name')) {
            $details['name'] = trim((string) $this->validated('name'));
        }
        if ($this->has('email')) {
            $details['email'] = (string) $this->validated('email');
        }
        if ($this->has('phone')) {
            $details['phone_e164'] = PhoneNumber::normalize($this->validated('phone'));
        }

        return $details;
    }

    /** @return list<int> */
    public function ids(string $key): array
    {
        return array_values(array_map('intval', (array) $this->validated($key, [])));
    }
}
