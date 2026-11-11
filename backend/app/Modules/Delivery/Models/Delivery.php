<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Models;

use App\Modules\Delivery\Enums\CodStatus;
use App\Modules\Delivery\Enums\DeliveryDocumentType;
use App\Modules\Delivery\Enums\DeliveryMethod;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Payments\Models\Payment;
use App\Support\Authorization\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The delivery record of an order (Phase 08), opened once (UNIQUE order_id) when sewing
 * completes. Written only by the Delivery Actions under the order + delivery locks with `version`.
 *
 * @property DeliveryStatus $status
 * @property DeliveryMethod|null $method
 * @property CodStatus $cod_status
 * @property int $version
 * @property int $cod_amount_minor
 * @property int|null $cod_collected_minor
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $delivered_at
 */
class Delivery extends Model
{
    use BranchScoped;

    protected $table = 'deliveries';

    protected bool $scopeByPageViaOrder = true;

    /** @var list<string> */
    protected $fillable = ['order_id', 'method', 'courier_name', 'tracking_no', 'scheduled_date', 'received_by_name', 'notes'];

    protected function casts(): array
    {
        return [
            'method' => DeliveryMethod::class,
            'status' => DeliveryStatus::class,
            'cod_status' => CodStatus::class,
            'scheduled_date' => 'date',
            'cod_amount_minor' => 'integer',
            'cod_collected_minor' => 'integer',
            'version' => 'integer',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Dispatched or delivered without the document its method requires (any one type of the set).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMissingDocuments(Builder $query): Builder
    {
        return $query->whereIn('deliveries.status', [DeliveryStatus::Dispatched->value, DeliveryStatus::Delivered->value])
            ->where(function (Builder $q): void {
                foreach (DeliveryMethod::cases() as $method) {
                    $types = array_map(fn (DeliveryDocumentType $t) => $t->value, DeliveryDocumentType::requiredFor($method));
                    $q->orWhere(fn (Builder $m) => $m->where('deliveries.method', $method->value)
                        ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('delivery_documents')->whereColumn('delivery_documents.delivery_id', 'deliveries.id')->whereIn('delivery_documents.type', $types)));
                }
            });
    }

    /** @return HasMany<DeliveryDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(DeliveryDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'proof_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<GatePass, $this> */
    public function gatePasses(): HasMany
    {
        return $this->hasMany(GatePass::class);
    }
}
