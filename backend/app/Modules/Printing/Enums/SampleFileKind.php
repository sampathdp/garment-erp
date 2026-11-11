<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

enum SampleFileKind: string
{
    use HasValues;

    case Image = 'image';       // the sample photo (JPG / PNG / WebP)
    case Document = 'document'; // related documents (image or PDF)
}
