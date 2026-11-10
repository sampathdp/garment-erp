<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Business dates (due dates, payment dates, holidays) are Asia/Colombo calendar dates,
 * while timestamps are stored in UTC. Always derive "today" through this class.
 */
final class BusinessDate
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Colombo');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** Calendar date (Asia/Colombo) of a UTC timestamp. */
    public static function of(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone())->startOfDay();
    }

    /** Parse a YYYY-MM-DD business date. */
    public static function parse(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone()) ?: throw new \InvalidArgumentException("Invalid date [{$date}].");
    }
}
