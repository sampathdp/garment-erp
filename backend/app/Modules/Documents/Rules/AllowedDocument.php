<?php

declare(strict_types=1);

namespace App\Modules\Documents\Rules;

use App\Modules\Documents\Enums\DocumentCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates an upload against its category policy using the file CONTENT.
 * Usage: ['file' => ['required', 'file', new AllowedDocument(DocumentCategory::PaymentSlip)]]
 */
final class AllowedDocument implements ValidationRule
{
    public function __construct(private readonly DocumentCategory $category) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a successfully uploaded file.');

            return;
        }

        if ($value->getSize() === false || $value->getSize() === 0 || $value->getSize() > $this->category->maxBytes()) {
            $fail(sprintf('The :attribute must not be empty or larger than %d MB.', intdiv($this->category->maxBytes(), 1024 * 1024)));

            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($value->getRealPath()) ?: 'application/octet-stream';

        if (! in_array($detected, $this->category->allowedMimeTypes(), true)) {
            $fail('The :attribute file type is not allowed.');

            return;
        }

        $name = $value->getClientOriginalName();
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $fail('The :attribute file name is invalid.');
        }
    }
}
