<?php

declare(strict_types=1);

namespace App\Modules\GatePass\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePassLine extends Model
{
    /** @var list<string> */
    protected $fillable = ['gate_pass_id', 'description', 'quantity_out', 'quantity_returned', 'unit', 'variance_note'];

    protected function casts(): array
    {
        return [
            'quantity_out' => 'integer',
            'quantity_returned' => 'integer',
        ];
    }

    /** @return BelongsTo<GatePass, $this> */
    public function gatePass(): BelongsTo
    {
        return $this->belongsTo(GatePass::class);
    }
}
