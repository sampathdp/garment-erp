<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): Response
    {
        // Before validation, so unauthorized callers learn nothing from validation errors.
        return Gate::inspect('administer', $this->route('user'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:20'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_map('strval', (array) $this->validated('roles', [])));
    }
}
