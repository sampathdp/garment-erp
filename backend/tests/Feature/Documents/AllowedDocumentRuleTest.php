<?php

declare(strict_types=1);

use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Rules\AllowedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

function validateUpload(UploadedFile $file, DocumentCategory $category): bool
{
    return Validator::make(['file' => $file], ['file' => ['required', 'file', new AllowedDocument($category)]])->passes();
}

it('accepts a real image for a payment slip', function () {
    expect(validateUpload(UploadedFile::fake()->image('slip.png', 200, 200), DocumentCategory::PaymentSlip))->toBeTrue();
});

it('rejects a PHP script disguised with an image extension and MIME header', function () {
    $file = UploadedFile::fake()->createWithContent('slip.png', '<?php system($_GET["c"]); ?>');

    expect(validateUpload($file, DocumentCategory::PaymentSlip))->toBeFalse();
});

it('rejects files over the category size limit', function () {
    $file = UploadedFile::fake()->image('logo.png')->size(3 * 1024); // 3 MB, logo limit is 2 MB

    expect(validateUpload($file, DocumentCategory::Logo))->toBeFalse();
});

it('rejects PDFs where only images are allowed', function () {
    $file = UploadedFile::fake()->createWithContent('logo.png', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

    expect(validateUpload($file, DocumentCategory::Logo))->toBeFalse();
});

it('forces download for formats that must never render inline', function () {
    expect(DocumentCategory::DesignSource->forceDownload())->toBeTrue()
        ->and(DocumentCategory::PaymentSlip->forceDownload())->toBeFalse();
});
