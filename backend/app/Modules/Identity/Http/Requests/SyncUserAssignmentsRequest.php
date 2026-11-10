<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SyncUserAssignmentsRequest extends FormRequest
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
            'branch_ids' => ['present', 'array', 'max:100'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
            'page_ids' => ['present', 'array', 'max:100'],
            'page_ids.*' => ['integer', 'distinct', Rule::exists('pages', 'id')],
            'owner_page_ids' => ['sometimes', 'array', 'max:100'],
            'owner_page_ids.*' => ['integer', 'distinct', 'in_array:page_ids.*'],
        ];
    }

    /** @return list<int> */
    public function ids(string $key): array
    {
        return array_values(array_map('intval', (array) $this->validated($key, [])));
    }
}
