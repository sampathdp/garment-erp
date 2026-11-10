<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Models;

use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Enums\InvoiceStatus;
use App\Modules\Invoices\Enums\InvoiceType;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Issued invoices are immutable snapshots: their lines and totals never change when price
 * lists change. Corrections void the invoice (with a reason) and issue a new one.
 *
 * @property InvoiceType $type
 * @property InvoiceStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $pricing_date
 */
class Invoice extends Model
{
    use BranchScoped;

    protected bool $scopeByPage = true;

    /**
     * Number, totals and status are written by the issuing Action. Issued invoices are immutable.
     *
     * @var list<string>
     */
    protected $fillable = ['order_id', 'type', 'related_invoice_id', 'currency'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'tax_percent' => 'integer',
            'pricing_date' => 'date',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
