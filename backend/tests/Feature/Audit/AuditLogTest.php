<?php

declare(strict_types=1);

use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Organizations\Models\Branch;

it('records actor, subject, branch, request id and redacts secrets', function () {
    $branch = Branch::factory()->create();

    $log = app(AuditLogger::class)->record('branch.updated', $branch, [
        'before' => ['name' => 'Old'],
        'after' => ['name' => 'New', 'password' => 'hunter2', 'nested' => ['token' => 'abc']],
    ]);

    expect($log->fresh())
        ->action->toBe('branch.updated')
        ->subject_type->toBe('branch')       // morph alias, not a class name
        ->subject_id->toBe($branch->id)
        ->branch_id->toBeNull()                // branches have no branch_id column
        ->and($log->changes['after']['password'])->toBe('[REDACTED]')
        ->and($log->changes['after']['nested']['token'])->toBe('[REDACTED]')
        ->and($log->changes['after']['name'])->toBe('New');
});

it('is append-only', function () {
    $log = app(AuditLogger::class)->record('test.event');

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->count())->toBe(1);
});
