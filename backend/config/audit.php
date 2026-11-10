<?php

declare(strict_types=1);

return [
    // Create MySQL triggers that reject UPDATE/DELETE on audit_logs. Disable only if the host forbids CREATE TRIGGER.
    'db_triggers' => (bool) env('AUDIT_DB_TRIGGERS', true),

    // Retention: audit rows are never deleted by the application. Archive yearly (see DATABASE_DESIGN.md §6).
    'retention_years' => 7,
];
