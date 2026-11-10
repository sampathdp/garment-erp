<?php

declare(strict_types=1);

namespace App\Modules\Design\Enums;

use App\Support\Enums\HasValues;

enum DesignFileKind: string
{
    use HasValues;

    case Source = 'source';
    case Preview = 'preview';
    case Mockup = 'mockup';
}
