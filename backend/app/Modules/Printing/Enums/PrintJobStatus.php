<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

enum PrintJobStatus: string
{
    use HasValues;

    case Preparing = 'preparing';
    case SampleReview = 'sample_review';
    case Printing = 'printing';
    case Printed = 'printed';
    case Invalidated = 'invalidated';
}
