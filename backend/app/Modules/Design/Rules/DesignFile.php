<?php

declare(strict_types=1);

namespace App\Modules\Design\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Design uploads, checked by CONTENT (magic bytes / finfo), never by the client MIME header:
 *  - preview: JPEG, PNG or WebP image
 *  - source : PDF (%PDF) or CorelDRAW .cdr (RIFF "CDR" container, or the ZIP container used
 *             since CorelDRAW X4). CDR files are only stored and downloaded, never rendered.
 */
final class DesignFile implements ValidationRule
{
    public const PREVIEW = 'preview';

    public const SOURCE = 'source';

    private const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly string $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a successfully uploaded file.');

            return;
        }

        $maxMb = $this->kind === self::PREVIEW ? 25 : (int) config('documents.design_source_max_mb', 200);
        $size = (int) $value->getSize();
        if ($size === 0 || $size > $maxMb * 1024 * 1024) {
            $fail("The :attribute must not be empty or larger than {$maxMb} MB.");

            return;
        }

        $name = $value->getClientOriginalName();
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $fail('The :attribute file name is invalid.');

            return;
        }

        if (self::detect($value, $this->kind) === null) {
            $fail($this->kind === self::PREVIEW ? 'The :attribute must be a JPG, PNG or WebP image.' : 'The :attribute must be a PDF or CDR file.');
        }
    }

    /** Detected type (`image/png`, `application/pdf`, `application/x-cdr`) or null when not allowed. */
    public static function detect(UploadedFile $file, string $kind): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';

        if ($kind === self::PREVIEW) {
            return in_array($mime, self::IMAGES, true) ? $mime : null;
        }

        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        if ($mime === 'application/pdf' && str_starts_with($head, '%PDF')) {
            return 'application/pdf';
        }

        $isCdrName = strtolower($file->getClientOriginalExtension()) === 'cdr';
        $riffCdr = str_starts_with($head, 'RIFF') && str_starts_with(substr($head, 8, 4), 'CDR');
        $zip = str_starts_with($head, "PK\x03\x04");

        return $isCdrName && ($riffCdr || $zip) ? 'application/x-cdr' : null;
    }
}
