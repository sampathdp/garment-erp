<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 05: bulk production management.
 *
 *  - bulk_jobs gains the current assignment (internal designer / external supplier), an
 *    optimistic-lock version, the review pointers (current / approved version, approved list
 *    reference) and the completion record.
 *  - bulk_job_assignments: append-only assignment history.
 *  - bulk_versions (+ files) and bulk_reviews: immutable submissions and the Page Owner decisions.
 *  - bulk_measurement_fields / bulk_job_measurements / bulk_job_measurement_changes: configurable
 *    job-level measurements (bulk meter, patch meter, ...) with a full change history.
 *  - bulk_job_documents: external evidence and client checklist confirmation evidence.
 *  - checklist_runs / checklist_run_items become per-job checklist instances (item snapshots).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_job_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->foreignId('previous_version_id')->nullable()->constrained('bulk_versions')->restrictOnDelete();
            // The confirmed name/number list version this bulk was prepared from (immutable reference).
            $table->foreignId('garment_list_version_id')->constrained()->restrictOnDelete();
            $table->boolean('is_final')->default(false);
            $table->string('notes', 2000)->nullable();
            $table->string('external_url', 500)->nullable(); // shared layout link, never fetched
            $table->string('transfer_url', 500)->nullable(); // WeTransfer link (final internal submission), never fetched
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['bulk_job_id', 'version_no']);
        });

        Schema::create('bulk_version_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // preview | file
            $table->timestamp('created_at')->nullable();

            $table->index('bulk_version_id');
        });

        Schema::create('bulk_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_version_id')->constrained()->restrictOnDelete();
            $table->string('decision', 20); // approved | changes_requested
            $table->string('reason', 1000)->nullable();
            $table->unsignedSmallInteger('round_no')->nullable(); // correction round (changes_requested)
            $table->boolean('is_system')->default(false);         // raised by a list correction, not by a reviewer decision
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index('bulk_version_id');
        });

        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->string('mode', 10)->nullable()->after('status'); // internal | external (null until assigned)
            $table->foreignId('supplier_id')->nullable()->after('bulk_designer_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('assignment_notes', 1000)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('current_version_id')->nullable()->constrained('bulk_versions')->restrictOnDelete();
            $table->foreignId('approved_version_id')->nullable()->constrained('bulk_versions')->restrictOnDelete();
            // Locked at approval: the list version the approved bulk was prepared from.
            $table->foreignId('approved_list_version_id')->nullable()->constrained('garment_list_versions')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedSmallInteger('correction_rounds')->default(0);
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_notes', 2000)->nullable();

            $table->index(['mode', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('bulk_job_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_job_id')->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->foreignId('bulk_designer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->string('change_reason', 500)->nullable(); // required for every reassignment
            $table->timestamp('ended_at')->nullable();        // null = current assignment
            $table->timestamps();

            $table->index(['bulk_job_id', 'created_at']);
        });

        Schema::create('bulk_measurement_fields', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->string('label', 80);
            $table->string('unit', 12)->default('m');
            $table->foreignId('product_type_id')->nullable()->constrained()->restrictOnDelete(); // null = every category
            $table->decimal('min_value', 12, 3)->default(0);
            $table->decimal('max_value', 12, 3)->default(100000);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_system')->default(false); // bulk meter / patch meter: key fixed, always active
            $table->boolean('show_on_job_card')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'product_type_id']);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('bulk_job_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('bulk_measurement_field_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 12, 3);
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('entered_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['bulk_job_id', 'bulk_measurement_field_id'], 'uq_bulk_job_measurement');
        });

        // Append-only: every entry and every change, with who and why.
        Schema::create('bulk_job_measurement_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('bulk_measurement_field_id')->constrained()->restrictOnDelete();
            $table->decimal('old_value', 12, 3)->nullable();
            $table->decimal('new_value', 12, 3);
            $table->boolean('is_correction')->default(false); // administrative correction after completion
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['bulk_job_id', 'created_at']);
        });

        Schema::create('bulk_job_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24); // evidence | client_confirmation
            $table->string('note', 500)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['bulk_job_id', 'kind']);
        });

        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->foreignId('bulk_job_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable(); // replaced when the production mode changes
            $table->timestamp('client_confirmed_at')->nullable();
            $table->foreignId('client_confirmed_by')->nullable()->constrained('users')->restrictOnDelete(); // staff member who recorded it
            $table->string('client_channel', 16)->nullable();
            $table->string('client_comment', 1000)->nullable();
            $table->foreignId('client_evidence_document_id')->nullable()->constrained('documents')->restrictOnDelete();

            $table->index(['bulk_job_id', 'superseded_at']);
        });

        Schema::table('checklist_run_items', function (Blueprint $table) {
            $table->string('result', 8)->nullable()->change(); // later stages use pass/fail/na; bulk uses completed flags
            // Snapshot of the template item, so template changes never alter a job's checklist.
            $table->string('label', 255)->default('');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_run_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['label', 'is_required', 'sort_order', 'is_completed', 'completed_at']);
        });
        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->dropIndex(['bulk_job_id', 'superseded_at']);
            $table->dropConstrainedForeignId('bulk_job_id');
            $table->dropConstrainedForeignId('client_confirmed_by');
            $table->dropConstrainedForeignId('client_evidence_document_id');
            $table->dropColumn(['superseded_at', 'client_confirmed_at', 'client_channel', 'client_comment']);
        });
        Schema::dropIfExists('bulk_job_documents');
        Schema::dropIfExists('bulk_job_measurement_changes');
        Schema::dropIfExists('bulk_job_measurements');
        Schema::dropIfExists('bulk_measurement_fields');
        Schema::dropIfExists('bulk_job_assignments');
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->dropIndex(['mode', 'status']);
            $table->dropIndex(['supplier_id', 'status']);
            foreach (['supplier_id', 'assigned_by', 'current_version_id', 'approved_version_id', 'approved_list_version_id', 'approved_by', 'completed_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['mode', 'assigned_at', 'assignment_notes', 'version', 'approved_at', 'correction_rounds', 'completed_at', 'completion_notes']);
        });
        Schema::dropIfExists('bulk_reviews');
        Schema::dropIfExists('bulk_version_files');
        Schema::dropIfExists('bulk_versions');
    }
};
