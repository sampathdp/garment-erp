<?php

declare(strict_types=1);

namespace App\Support\Enums;

trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
