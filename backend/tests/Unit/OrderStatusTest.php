<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\Department;
use App\Modules\Orders\Enums\OrderStatus;

it('allows only the documented forward transitions', function () {
    expect(OrderStatus::Draft->canTransitionTo(OrderStatus::PendingPaymentVerification))->toBeTrue()
        ->and(OrderStatus::Draft->canTransitionTo(OrderStatus::UrgentApprovalPending))->toBeTrue()
        ->and(OrderStatus::Draft->canTransitionTo(OrderStatus::Confirmed))->toBeFalse()
        ->and(OrderStatus::UrgentApprovalRejected->canTransitionTo(OrderStatus::Confirmed))->toBeFalse()
        ->and(OrderStatus::PendingPaymentVerification->canTransitionTo(OrderStatus::Confirmed))->toBeTrue()
        ->and(OrderStatus::Draft->canTransitionTo(OrderStatus::Printing))->toBeFalse()
        ->and(OrderStatus::Cutting->canTransitionTo(OrderStatus::Sewing))->toBeTrue()
        ->and(OrderStatus::Cutting->canTransitionTo(OrderStatus::QualityCheck))->toBeFalse()
        ->and(OrderStatus::Sewing->canTransitionTo(OrderStatus::QualityCheck))->toBeTrue()
        ->and(OrderStatus::QualityCheck->canTransitionTo(OrderStatus::Sewing))->toBeTrue() // rework
        ->and(OrderStatus::Completed->canTransitionTo(OrderStatus::Cancelled))->toBeFalse();
});

it('allows hold from active states and cancel from active or held states', function () {
    expect(OrderStatus::Printing->canTransitionTo(OrderStatus::OnHold))->toBeTrue()
        ->and(OrderStatus::OnHold->canTransitionTo(OrderStatus::OnHold))->toBeFalse()
        ->and(OrderStatus::OnHold->canTransitionTo(OrderStatus::Cancelled))->toBeTrue()
        ->and(OrderStatus::Cancelled->canTransitionTo(OrderStatus::OnHold))->toBeFalse();
});

it('requires late-cancellation approval from printing onwards', function () {
    expect(OrderStatus::PrintPreparation->requiresLateCancellation())->toBeFalse()
        ->and(OrderStatus::Printing->requiresLateCancellation())->toBeTrue()
        ->and(OrderStatus::Delivered->requiresLateCancellation())->toBeTrue();
});

it('gives every active non-terminal state a next state', function () {
    foreach (OrderStatus::cases() as $status) {
        if ($status->isActive()) {
            expect($status->nextStates())->not->toBeEmpty("{$status->value} is a dead end");
        }
    }
});

it('derives an assigned department for every status', function () {
    foreach (OrderStatus::cases() as $status) {
        expect($status->department())->toBeInstanceOf(Department::class);
    }

    expect(OrderStatus::forDepartment(Department::Sewing))->toBe([OrderStatus::Sewing]);
});
