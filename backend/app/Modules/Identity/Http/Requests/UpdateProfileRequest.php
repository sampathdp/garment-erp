<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Validation\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/** Own profile: name and phone only. Email, roles and assignments are changed by administrators. */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', PhoneNumber::rule()],
        ];
    }

    /** @return array{name: string, phone_e164: string|null} */
    public function profile(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'phone_e164' => PhoneNumber::normalize($this->validated('phone')),
        ];
    }
}
