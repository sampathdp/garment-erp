<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Payments: Second Admins verify; the recorder can never verify their own payment. */
final class PaymentPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PAYMENTS_VIEW);
    }

    public function view(User $user, Payment $payment): Response
    {
        return $this->allowScoped($user, $payment, Permissions::PAYMENTS_VIEW);
    }

    public function verify(User $user, Payment $payment): Response
    {
        $response = $this->decide(
            $payment->isVisibleTo($user),
            $user->can(Permissions::PAYMENTS_VERIFY),
            $payment->status === PaymentStatus::PendingVerification,
            'This payment has already been decided.',
        );

        if ($response->allowed() && (int) $payment->getAttribute('recorded_by') === $user->id) {
            return Response::deny('You cannot verify a payment you recorded.', 'separation_of_duties');
        }

        return $response;
    }

    public function refund(User $user, Payment $payment): Response
    {
        return $this->decide(
            $payment->isVisibleTo($user),
            $user->can(Permissions::PAYMENTS_REFUND),
            $payment->status === PaymentStatus::Verified,
            'Only verified payments can be refunded.',
        );
    }
}
