<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Snapshot of one priced line. Never recalculated after the invoice is issued. */
class InvoiceItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price_minor', 'line_total_minor', 'sort_order', 'product_type_id', 'price_list_item_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice lines are immutable.'));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
