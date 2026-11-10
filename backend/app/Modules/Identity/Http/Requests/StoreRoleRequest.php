<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->isMethod('POST') ? Gate::inspect('create', Role::class) : Gate::inspect('update', $this->route('role'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $role = $this->route('role');
        $creating = $this->isMethod('POST');
        $system = $role instanceof Role && $role->is_system;

        return [
            // System role names are referenced by seeders and docs: they cannot be renamed.
            'name' => $creating
                ? ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->where('guard_name', 'web')]
                : ($system ? ['prohibited'] : ['sometimes', 'string', 'min:3', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id)]),
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => [$creating ? 'present' : 'sometimes', 'array', 'max:200'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::all())],
        ];
    }

    public function messages(): array
    {
        return ['name.regex' => 'Use lowercase letters, numbers and underscores, starting with a letter.'];
    }

    /** @return array<string, string|null> */
    public function attributesToSave(): array
    {
        return array_intersect_key($this->validated(), array_flip(['name', 'label', 'description']));
    }

    /** @return list<string>|null null = permissions not sent */
    public function permissions(): ?array
    {
        return $this->has('permissions') ? array_values(array_map('strval', (array) $this->validated('permissions', []))) : null;
    }
}
