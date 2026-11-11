<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 07: cutting department, quality control and production issue management.
 *
 *  - cutting_jobs: one per printing job (UNIQUE print_job_id), opened when the gate pass is
 *    recorded after physical printing. The cutter is a USER (supervisor_id), so the earlier
 *    "production unit or supplier" CHECK is dropped and `mode` becomes optional. Assignment,
 *    expected completion, completion record and an optimistic-lock version are added.
 *  - cutting_job_assignments: append-only assignment history.
 *  - cutting_job_quantities: expected (from the locked list) and cut quantity per size.
 *  - cutting_job_events: append-only status history.
 *  - checklist_runs.cutting_job_id: cutting checklist and quality check instances of a job.
 *  - checklist_run_items.responsible_user_id: the person responsible for a checklist task.
 *  - production_issues: the central issue register (category, quantity affected, image,
 *    assignee, blocking flag, version); production_issue_events: its status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        CheckConstraint::drop('cutting_jobs', 'chk_cj_target');

        Schema::table('cutting_jobs', function (Blueprint $table) {
            $table->string('mode', 10)->nullable()->change();
            $table->string('status', 30)->change();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->date('expected_completion_date')->nullable(); // business date, Asia/Colombo
            $table->string('assignment_notes', 1000)->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('completion_notes', 1000)->nullable();

            $table->unique('print_job_id');
            $table->index(['supervisor_id', 'status']);
            $table->index(['status', 'expected_completion_date']);
        });
        CheckConstraint::add('cutting_jobs', 'chk_cj_cut_qty', 'cut_quantity <= planned_quantity');

        Schema::create('cutting_job_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutting_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('cutter_id')->constrained('users')->restrictOnDelete();
            $table->date('expected_completion_date');
            $table->string('notes', 1000)->nullable();
            $table->string('reason', 1000)->nullable(); // required for a reassignment
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['cutting_job_id', 'id']);
        });

        Schema::create('cutting_job_quantities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutting_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('size_label', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('cut_quantity')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cutting_job_id', 'size_label']);
        });
        CheckConstraint::add('cutting_job_quantities', 'chk_cjq_qty', 'cut_quantity <= expected_quantity');

        Schema::create('cutting_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutting_job_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 1000)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['cutting_job_id', 'id']);
        });

        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->foreignId('cutting_job_id')->nullable()->after('bulk_job_id')->constrained()->restrictOnDelete();
            $table->index(['cutting_job_id', 'stage']);
        });

        Schema::table('checklist_run_items', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('production_issues', function (Blueprint $table) {
            $table->string('status', 24)->change();
            $table->foreignId('cutting_job_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_affected')->nullable();
            $table->string('part', 20)->nullable();       // garment part for missing parts (sleeve, collar ...)
            $table->string('size_label', 60)->nullable(); // size of the affected pieces, when known
            $table->foreignId('image_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->boolean('is_blocking')->default(true);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->index(['order_id', 'status']);
            $table->index(['cutting_job_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['type', 'status']);
        });
        CheckConstraint::add('production_issues', 'chk_pi_qty', 'quantity_affected IS NULL OR quantity_affected >= 1');

        Schema::create('production_issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_issue_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('note', 2000)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['production_issue_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_issue_events');
        CheckConstraint::drop('production_issues', 'chk_pi_qty');
        Schema::table('production_issues', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'status']);
            $table->dropIndex(['cutting_job_id', 'status']);
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropIndex(['type', 'status']);
            foreach (['cutting_job_id', 'image_document_id', 'assigned_to', 'closed_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['quantity_affected', 'part', 'size_label', 'is_blocking', 'assigned_at', 'closed_at', 'version']);
            $table->string('status', 16)->change();
        });
        Schema::table('checklist_run_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
        });
        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->dropIndex(['cutting_job_id', 'stage']);
            $table->dropConstrainedForeignId('cutting_job_id');
        });
        Schema::dropIfExists('cutting_job_events');
        CheckConstraint::drop('cutting_job_quantities', 'chk_cjq_qty');
        Schema::dropIfExists('cutting_job_quantities');
        Schema::dropIfExists('cutting_job_assignments');
        CheckConstraint::drop('cutting_jobs', 'chk_cj_cut_qty');
        Schema::table('cutting_jobs', function (Blueprint $table) {
            $table->dropUnique(['print_job_id']);
            $table->dropIndex(['supervisor_id', 'status']);
            $table->dropIndex(['status', 'expected_completion_date']);
            foreach (['assigned_by', 'completed_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['version', 'assigned_at', 'expected_completion_date', 'assignment_notes', 'completion_notes']);
            $table->string('status', 16)->change();
        });
    }
};
