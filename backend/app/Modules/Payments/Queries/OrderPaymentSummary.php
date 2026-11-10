<?php

declare(strict_types=1);

namespace App\Modules\Payments\Queries;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;

/**
 * Payment figures of one order, read by the Orders module (it never queries `payments` itself).
 * Only VERIFIED payments count towards the advance; refunds arrive with the refund flow.
 */
final class OrderPaymentSummary
{
    /** @return array{verified_minor: int, pending_minor: int, pending_count: int, last_decision: PaymentStatus|null} */
    public function forOrder(int $orderId): array
    {
        $rows = Payment::query()->where('order_id', $orderId)
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount_minor), 0) as total')
            ->groupBy('status')
            ->toBase()
            ->get()
            ->keyBy('status');

        $lastDecision = Payment::query()->where('order_id', $orderId)
            ->whereIn('status', [PaymentStatus::Verified->value, PaymentStatus::Rejected->value])
            ->orderByDesc('verified_at')->orderByDesc('id')
            ->value('status');

        return [
            'verified_minor' => (int) ($rows[PaymentStatus::Verified->value]->total ?? 0),
            'pending_minor' => (int) ($rows[PaymentStatus::PendingVerification->value]->total ?? 0),
            'pending_count' => (int) ($rows[PaymentStatus::PendingVerification->value]->n ?? 0),
            'last_decision' => $lastDecision instanceof PaymentStatus ? $lastDecision : ($lastDecision === null ? null : PaymentStatus::from((string) $lastDecision)),
        ];
    }
}
