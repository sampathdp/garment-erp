<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Also serves as the activity feed (activity_logs merged here).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable(); // no FK: audit rows must outlive any reference
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->json('changes')->nullable(); // before/after diff (allowed exception)

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        // Defence in depth: reject UPDATE/DELETE at the database level where triggers are allowed.
        // Shared hosting may forbid CREATE TRIGGER; append-only is ALSO enforced in the model.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && config('audit.db_triggers', true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }
        Schema::dropIfExists('audit_logs');
    }
};
