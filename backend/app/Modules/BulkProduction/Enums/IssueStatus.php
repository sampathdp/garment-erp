<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

/**
 * Production issue statuses (Phase 07). Changed only through ProductionIssueWorkflow.
 *
 *   open → under_investigation | correction_required | resolved
 *   under_investigation ⇄ correction_required, either → resolved
 *   resolved → closed, or → open (reopened by an issue manager)
 *
 * Open, Under Investigation and Correction Required are UNRESOLVED: a blocking unresolved
 * issue prevents cutting completion and job completion.
 */
enum IssueStatus: string
{
    use HasValues;

    case Open = 'open';
    case UnderInvestigation = 'under_investigation';
    case CorrectionRequired = 'correction_required';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> */
    public function nextStates(): array
    {
        return match ($this) {
            self::Open => [self::UnderInvestigation, self::CorrectionRequired, self::Resolved],
            self::UnderInvestigation => [self::CorrectionRequired, self::Resolved],
            self::CorrectionRequired => [self::UnderInvestigation, self::Resolved],
            self::Resolved => [self::Closed, self::Open],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function isUnresolved(): bool
    {
        return in_array($this, self::unresolved(), true);
    }

    /** @return list<self> */
    public static function unresolved(): array
    {
        return [self::Open, self::UnderInvestigation, self::CorrectionRequired];
    }

    /** @return list<string> */
    public static function unresolvedValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::unresolved());
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
