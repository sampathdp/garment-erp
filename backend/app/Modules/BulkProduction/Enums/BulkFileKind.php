<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum BulkFileKind: string
{
    use HasValues;

    case Preview = 'preview'; // JPG / PNG / WebP image of the layout
    case File = 'file';       // PDF / CDR layout file
}
