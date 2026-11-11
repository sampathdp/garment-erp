<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 06: printing management and printing sample approval.
 *
 *  - print_jobs: one per completed bulk job (UNIQUE bulk_job_id), the final bulk version it was
 *    opened from, an optimistic-lock version, the "sent for printing" record, the approved
 *    sample and the physical printing record. The status column grows for the new statuses.
 *  - print_samples: immutable sample versions (uploader, notes, review status, rejection reason).
 *  - print_sample_documents: the sample image and related documents of each version.
 *  - print_job_events: append-only status history of the controlled transitions.
 *  - print_job_gate_passes: gate pass details recorded after physical printing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('status', 30)->change();
            $table->foreignId('bulk_job_id')->nullable()->after('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('bulk_version_id')->nullable()->after('garment_list_version_id')->constrained()->restrictOnDelete(); // final internal bulk version
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('recipient_name', 160)->nullable(); // printing company / recipient when not a registered supplier
            $table->string('send_notes', 1000)->nullable();
            $table->foreignId('printing_started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('printing_started_at')->nullable();
            $table->foreignId('printing_completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('printing_completed_at')->nullable();
            $table->string('completion_notes', 1000)->nullable();

            $table->unique('bulk_job_id');
        });

        Schema::table('print_samples', function (Blueprint $table) {
            $table->string('notes', 2000)->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('approved_sample_id')->nullable()->constrained('print_samples')->restrictOnDelete();
        });

        Schema::create('print_sample_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_sample_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // image | document
            $table->timestamp('created_at')->nullable();

            $table->index('print_sample_id');
        });

        Schema::create('print_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 1000)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['print_job_id', 'id']);
        });

        Schema::create('print_job_gate_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained()->restrictOnDelete();
            $table->string('gate_pass_no', 50);
            $table->date('gate_pass_date');
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['print_job_id', 'gate_pass_no']);
            $table->index('gate_pass_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_job_gate_passes');
        Schema::dropIfExists('print_job_events');
        Schema::dropIfExists('print_sample_documents');
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_sample_id');
        });
        Schema::table('print_samples', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['notes', 'rejection_reason']);
        });
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropUnique(['bulk_job_id']);
            foreach (['bulk_job_id', 'bulk_version_id', 'sent_by', 'printing_started_by', 'printing_completed_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['version', 'sent_at', 'recipient_name', 'send_notes', 'printing_started_at', 'printing_completed_at', 'completion_notes']);
            $table->string('status', 16)->change();
        });
    }
};
