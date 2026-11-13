<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Adds CHECK constraints (MySQL 8.0.16+ / MariaDB). The schema builder has no CHECK support.
 * Business invariants are ALSO enforced in Actions; the constraints are the last line of defence.
 */
final class CheckConstraint
{
    public static function add(string $table, string $name, string $expression): void
    {
        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    public static function drop(string $table, string $name): void
    {
        // MySQL 8.0.19+ and MariaDB both accept DROP CONSTRAINT for CHECK constraints.
        DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', $table, $name));
    }
}
