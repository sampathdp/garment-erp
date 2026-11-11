<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

/** Documents that can be generated for printing from a printing job. */
enum PrintDocument: string
{
    use HasValues;

    case JobCard = 'job-card';
    case NameList = 'name-list';

    public function label(): string
    {
        return match ($this) {
            self::JobCard => 'Job Card',
            self::NameList => 'Name/Number List',
        };
    }
}
