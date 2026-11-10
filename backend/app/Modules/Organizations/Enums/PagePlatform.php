<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Enums;

use App\Support\Enums\HasValues;

enum PagePlatform: string
{
    use HasValues;

    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Website = 'website';
    case WalkIn = 'walk_in';
    case Other = 'other';
}
