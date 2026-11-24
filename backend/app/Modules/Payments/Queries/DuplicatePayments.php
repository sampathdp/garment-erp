<?php

declare(strict_types=1);

namespace App\Modules\Payments\Queries;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate payment detection (WORKFLOW_REQUIREMENTS.md §3.4): a payment is a duplicate when a
 * payment recorded on ANOTHER page has the same amount, bank, payment date and payment time.
 * Rejected payments never count as a match. Payments without a bank or time never match.
 *
 * The flag is derived on read, so both payments show "Duplicate" for as long as both exist,
 * whichever was entered first. Index: payments_duplicate_match_index.
 */
final class DuplicatePayments
{
    /** Correlated sub-select for list queries: 1 when the outer `payments` row has a match, else null. */
    public static function flag(): Builder
    {
        return DB::table('payments as dup')->selectRaw('1')
            ->whereColumn('dup.amount_minor', 'payments.amount_minor')
            ->whereColumn('dup.paid_at', 'payments.paid_at')
            ->whereColumn('dup.payment_time', 'payments.payment_time')
            ->whereColumn('dup.bank_name', 'payments.bank_name')
            ->whereColumn('dup.page_id', '<>', 'payments.page_id')
            ->where('dup.status', '<>', PaymentStatus::Rejected->value)
            ->limit(1);
    }

    /** @return Collection<int, Payment> payments on other pages that match this one */
    public static function matches(Payment $payment): Collection
    {
        $time = $payment->getAttribute('payment_time');
        if ($payment->bank_name === null || $time === null) {
            return new Collection;
        }

        return Payment::query()
            ->select(['id', 'order_id', 'branch_id', 'page_id', 'amount_minor', 'status', 'recorded_by'])
            ->with(['order:id,order_no,page_id', 'order.page:id,code,name'])
            ->whereKeyNot($payment->id)
            ->where('amount_minor', $payment->amount_minor)
            ->where('paid_at', $payment->paid_at)
            ->where('payment_time', $time)
            ->where('bank_name', $payment->bank_name)
            ->where('page_id', '<>', $payment->getAttribute('page_id'))
            ->where('status', '<>', PaymentStatus::Rejected->value)
            ->orderBy('id')
            ->limit(20)
            ->get();
    }
}
