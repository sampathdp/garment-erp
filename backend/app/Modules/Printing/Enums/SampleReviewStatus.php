<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

/** Review status of one printing sample version (derived from its decision). */
enum SampleReviewStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function fromDecision(?SampleDecision $decision): self
    {
        return match ($decision) {
            null => self::Pending,
            SampleDecision::Approved => self::Approved,
            SampleDecision::Rejected => self::Rejected,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
