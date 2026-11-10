<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Adds CHECK constraints on MySQL/MariaDB.
 *
 * The schema builder has no portable CHECK support. SQLite (used for fast local
 * tests) cannot add constraints after table creation, so constraints are only
 * applied on MySQL-family connections. Business invariants are ALSO enforced in
 * Actions, so tests on SQLite still cover the rules; CI runs on MySQL to verify
 * the constraints themselves.
 */
final class CheckConstraint
{
    public static function add(string $table, string $name, string $expression): void
    {
        if (! self::supported()) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    public static function drop(string $table, string $name): void
    {
        if (! self::supported()) {
            return;
        }

        // MySQL 8.0.19+ and MariaDB both accept DROP CONSTRAINT for CHECK constraints.
        DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', $table, $name));
    }

    public static function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
