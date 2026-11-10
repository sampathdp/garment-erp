<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for record-level policies. Every decision checks, in this order:
 *
 *   1. scope      record outside the user's branches/pages          → 404 (existence not revealed)
 *   2. ownership  "assigned only" user and record not assigned to them → 404
 *   3. permission missing permission                                → 403
 *   4. state      record in a state that does not allow the action  → 409
 *
 * "Assigned only" users hold `<area>.view_assigned` instead of `<area>.view`: they can act only
 * on records whose assignee column is their own id (designer, bulk designer, cutting staff).
 */
abstract class ScopedPolicy
{
    protected function allowScoped(User $user, Model $record, string $permission): Response
    {
        if (! $this->inScope($user, $record)) {
            return Response::denyAsNotFound();
        }

        return $user->can($permission) ? Response::allow() : Response::deny();
    }

    protected function inScope(User $user, Model $record): bool
    {
        if (method_exists($record, 'isVisibleTo')) {
            return $record->isVisibleTo($user);
        }

        return false; // deny by default when a model has no scope definition
    }

    /** Scope check for child records without their own branch/page columns (scope comes from the order). */
    protected function orderInScope(User $user, ?int $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        $order = Order::query()->select(['id', 'branch_id', 'page_id'])->find($orderId);

        return $order instanceof Order && $order->isVisibleTo($user);
    }

    /**
     * May the user see the record at all? Users with $viewAll see everything in scope;
     * users with only $viewAssigned see records assigned to them. Anything else is hidden (404).
     */
    protected function canSee(User $user, bool $inScope, string $viewAll, ?string $viewAssigned, ?int $assigneeId): bool
    {
        if (! $inScope) {
            return false;
        }

        if ($user->can($viewAll)) {
            return true;
        }

        return $viewAssigned !== null && $user->can($viewAssigned) && $assigneeId !== null && $assigneeId === $user->id;
    }

    /**
     * Visibility + action permission + state, in the documented order.
     *
     * @param  bool  $stateAllows  whether the record's current state permits the action
     */
    protected function decide(bool $visible, bool $permitted, bool $stateAllows = true, string $stateMessage = 'The record is not in a state that allows this action.'): Response
    {
        if (! $visible) {
            return Response::denyAsNotFound();
        }

        if (! $permitted) {
            return Response::deny();
        }

        return $stateAllows ? Response::allow() : $this->conflict($stateMessage);
    }

    protected function conflict(string $message, string $code = 'invalid_state'): Response
    {
        return Response::denyWithStatus(409, $message, $code);
    }
}
