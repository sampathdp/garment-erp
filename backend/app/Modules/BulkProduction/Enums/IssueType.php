<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

/**
 * Production issue categories. Piece defects (damaged pieces, incorrect cuts, missing parts)
 * count against the pieces cut; bulk-related categories also notify the job's Bulk Designer.
 */
enum IssueType: string
{
    use HasValues;

    case DamagedPieces = 'damaged_pieces';
    case IncorrectCut = 'incorrect_cut';
    case MissingParts = 'missing_parts';   // missing sleeves, collars or other parts
    case MissingItems = 'missing_items';   // whole garments missing against the list
    case PrintingDefect = 'printing_defect';
    case BulkError = 'bulk_error';         // wrong bulk layout, measurement or name/number
    case CorrectionRequest = 'correction_request';
    case Material = 'material';
    case Machine = 'machine';
    case Quality = 'quality';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DamagedPieces => 'Damaged pieces',
            self::IncorrectCut => 'Incorrect cut',
            self::MissingParts => 'Missing sleeves / parts',
            self::MissingItems => 'Missing items',
            self::PrintingDefect => 'Printing defect',
            self::BulkError => 'Bulk error',
            self::CorrectionRequest => 'Correction request',
            self::Material => 'Material',
            self::Machine => 'Machine',
            self::Quality => 'Quality',
            self::Other => 'Other',
        };
    }

    /** Defective pieces among those already cut (their quantity cannot exceed the cut quantity). */
    public function isPieceDefect(): bool
    {
        return in_array($this, [self::DamagedPieces, self::IncorrectCut, self::MissingParts], true);
    }

    /** A quantity affected is mandatory for these categories. */
    public function requiresQuantity(): bool
    {
        return $this->isPieceDefect() || $this === self::MissingItems || $this === self::PrintingDefect;
    }

    /** Bulk-related: the order's Bulk Designer is notified as well (Phase 07 brief). */
    public function isBulkRelated(): bool
    {
        return $this === self::BulkError || $this === self::CorrectionRequest;
    }

    /** Default for the blocking flag; issue managers can change it when reporting. */
    public function blocksByDefault(): bool
    {
        return $this !== self::Machine && $this !== self::Other;
    }
}
