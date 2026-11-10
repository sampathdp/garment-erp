<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

final class SetUserPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
            'sign_out' => ['sometimes', 'boolean'],
        ];
    }
}
