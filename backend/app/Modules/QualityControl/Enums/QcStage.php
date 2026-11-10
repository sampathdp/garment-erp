<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Enums;

use App\Support\Enums\HasValues;

enum QcStage: string
{
    use HasValues;

    case PostPrint = 'post_print';
    case PostCut = 'post_cut';
    case PostSew = 'post_sew';
    case Final = 'final';
}
