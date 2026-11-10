<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

use App\Support\Enums\HasValues;

/**
 * File categories and their upload policy (SECURITY_STANDARDS.md §6).
 * MIME types are checked against the file CONTENT (finfo), never the extension or client header.
 */
enum DocumentCategory: string
{
    use HasValues;

    case PaymentSlip = 'payment_slip';
    case DesignSource = 'design_source';
    case DesignPreview = 'design_preview';
    case SamplePhoto = 'sample_photo';
    case ProofOfDelivery = 'proof_of_delivery';
    case Evidence = 'evidence';
    case InvoicePdf = 'invoice_pdf';
    case ImportFile = 'import_file';
    case Logo = 'logo';
    case ProductionFile = 'production_file';
    case DesignInstruction = 'design_instruction';
    case BulkPreview = 'bulk_preview'; // bulk layout preview image (validated with DesignFile::PREVIEW)
    case BulkFile = 'bulk_file';       // bulk layout PDF / CDR (validated with DesignFile::SOURCE)

    private const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::PaymentSlip, self::SamplePhoto, self::ProofOfDelivery, self::Evidence, self::DesignPreview, self::BulkPreview, self::ProductionFile, self::DesignInstruction => [...self::IMAGES, 'application/pdf'],
            self::Logo => self::IMAGES,
            self::InvoicePdf => ['application/pdf'],
            self::ImportFile => ['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            self::DesignSource, self::BulkFile => [
                'application/pdf', 'application/postscript', 'image/vnd.adobe.photoshop', 'application/x-photoshop',
                'image/svg+xml', 'application/zip', 'application/x-zip-compressed', 'application/cdr', 'application/x-cdr',
                'image/x-coreldraw', ...self::IMAGES,
            ],
        };
    }

    public function maxBytes(): int
    {
        $mb = match ($this) {
            self::Logo => 2,
            self::ImportFile => 5,
            self::PaymentSlip, self::SamplePhoto, self::ProofOfDelivery, self::Evidence, self::InvoicePdf, self::ProductionFile => 10,
            self::DesignInstruction => 25,
            self::DesignPreview, self::BulkPreview => 25,
            self::DesignSource, self::BulkFile => (int) config('documents.design_source_max_mb', 200),
        };

        return $mb * 1024 * 1024;
    }

    /** SVG and archives must never be rendered inline (stored XSS / zip contents). */
    public function forceDownload(): bool
    {
        return $this === self::DesignSource || $this === self::BulkFile || $this === self::ImportFile;
    }
}
