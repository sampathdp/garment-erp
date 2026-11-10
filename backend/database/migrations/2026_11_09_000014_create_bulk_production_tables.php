<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bulk assignment of (part of) an order item to an internal unit or an external supplier.
        Schema::create('bulk_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->string('mode', 10); // internal | external
            $table->foreignId('production_unit_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('supplier_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->unsignedInteger('quantity');
            $table->date('due_date');
            $table->string('status', 16); // WorkStatus: pending | in_progress | completed | cancelled
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'due_date']);
            $table->index(['supplier_id', 'status']);
            $table->index(['order_id', 'order_item_id']);
        });
        CheckConstraint::add('bulk_assignments', 'chk_ba_qty', 'quantity >= 1');
        CheckConstraint::add('bulk_assignments', 'chk_ba_target', "(mode = 'internal' AND production_unit_id IS NOT NULL AND supplier_id IS NULL) OR (mode = 'external' AND supplier_id IS NOT NULL AND production_unit_id IS NULL)");

        Schema::create('bulk_assignment_documents', function (Blueprint $table) {
            $table->foreignId('bulk_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['bulk_assignment_id', 'document_id']);
        });

        Schema::create('bulk_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_id')->constrained()->restrictOnDelete();
            $table->foreignId('measurement_point_id')->constrained()->restrictOnDelete();
            $table->decimal('measured_value', 7, 2);
            $table->boolean('within_tolerance');
            $table->string('accepted_reason', 500)->nullable(); // required when out of tolerance (enforced in Action)
            $table->foreignId('measured_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('measured_at');
            $table->timestamps();

            $table->index(['bulk_assignment_id', 'size_id']);
        });

        // A completed checklist (bulk, printing, cutting, sewing, QC, dispatch).
        Schema::create('checklist_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('stage', 32);
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'stage']);
        });

        Schema::create('checklist_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_item_id')->constrained()->restrictOnDelete();
            $table->string('result', 8); // pass | fail | na
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['checklist_run_id', 'checklist_template_item_id'], 'uq_checklist_run_item');
        });

        // Correction requests and problems raised during production (bulk corrections, cutting issues ...).
        Schema::create('production_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->string('stage', 32);
            $table->string('type', 24);   // correction_request | material | machine | quality | other
            $table->string('status', 16); // open | resolved | dismissed
            $table->string('description', 2000);
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 2000)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['order_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_issues');
        Schema::dropIfExists('checklist_run_items');
        Schema::dropIfExists('checklist_runs');
        Schema::dropIfExists('bulk_measurements');
        Schema::dropIfExists('bulk_assignment_documents');
        Schema::dropIfExists('bulk_assignments');
    }
};
