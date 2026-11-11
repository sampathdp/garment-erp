<?php

declare(strict_types=1);

namespace App\Modules\Printing\Rules;

use App\Modules\Documents\Enums\DocumentCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The printing sample image, checked by CONTENT, never by the extension or the client header:
 *  - size: 1 byte … SamplePhoto limit (10 MB);
 *  - type: finfo must detect JPEG, PNG or WebP;
 *  - content: the image header must decode (getimagesize) to the same type, with sane
 *    dimensions, so a script or HTML file renamed to .png is refused.
 */
final class SampleImage implements ValidationRule
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_DIMENSION = 20000;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a successfully uploaded file.');

            return;
        }

        $max = DocumentCategory::SamplePhoto->maxBytes();
        $size = (int) $value->getSize();
        if ($size === 0 || $size > $max) {
            $fail(sprintf('The :attribute must not be empty or larger than %d MB.', intdiv($max, 1024 * 1024)));

            return;
        }

        $name = $value->getClientOriginalName();
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $fail('The :attribute file name is invalid.');

            return;
        }

        if (self::detect($value) === null) {
            $fail('The :attribute must be a valid JPG, PNG or WebP image.');
        }
    }

    /** The detected image MIME type, or null when the file is not a decodable allowed image. */
    public static function detect(UploadedFile $file): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
        if (! in_array($mime, self::MIME_TYPES, true)) {
            return null;
        }

        $info = @getimagesize($file->getRealPath());
        if ($info === false || $info['mime'] !== $mime) {
            return null;
        }
        [$width, $height] = $info;

        return $width >= 1 && $height >= 1 && $width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION ? $mime : null;
    }
}
