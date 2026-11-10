<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SendPasswordResetRequest extends FormRequest
{
    public function authorize(): Response
    {
        // Before validation, so unauthorized callers learn nothing from validation errors.
        return Gate::inspect('administer', $this->route('user'));
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['sign_out' => ['sometimes', 'boolean']];
    }
}
