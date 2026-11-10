<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;

/** Workflow setting (scalar value). Read and written only through WorkflowSettings. */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by'];
}
