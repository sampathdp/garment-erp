<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 08: minimal sewing step, delivery, final job completion and the notification outbox.
 *
 *  - sewing_jobs: one per cutting job (UNIQUE cutting_job_id), opened at cutting completion. The
 *    "unit or supplier" CHECK is dropped (internal/external sewing is still Q21) and `mode`
 *    becomes optional; version, start/completion records.
 *  - deliveries: one per order (UNIQUE order_id), opened when sewing completes. `method` is
 *    chosen at dispatch / pickup; version, dispatcher, deliverer.
 *  - delivery_documents: delivery sheet, waybill, delivery / customer photos (private disk).
 *  - orders: who completed the job and the completion notes (completed_at already exists).
 *  - notification_outbox: one row per recipient, written in the business transaction; delivered
 *    by a queued job and a scheduled sweep, so a failed job never loses a notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        CheckConstraint::drop('sewing_jobs', 'chk_sj_target');
        Schema::table('sewing_jobs', function (Blueprint $table) {
            $table->string('mode', 10)->nullable()->change();
            $table->string('status', 30)->change();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();

            $table->unique('cutting_job_id');
            $table->index(['status', 'created_at']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('method', 16)->nullable()->change();
            $table->string('status', 20)->change();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->unique('order_id');
            $table->index(['status', 'delivered_at']);
        });

        Schema::create('delivery_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // delivery_sheet | waybill | delivery_photo | customer_photo
            $table->string('notes', 500)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['delivery_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('completion_notes', 1000)->nullable();
            $table->index(['status', 'completed_at']);
        });

        // JSON payload: notification display data (allowed exception, like `notifications.data`).
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id')->unique(); // becomes notifications.id (idempotent delivery)
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event', 60);
            $table->json('payload');
            $table->string('status', 12); // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn('completion_notes');
        });
        Schema::dropIfExists('delivery_documents');
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->dropIndex(['status', 'delivered_at']);
            $table->dropConstrainedForeignId('dispatched_by');
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropColumn('version');
            $table->string('status', 16)->change();
        });
        Schema::table('sewing_jobs', function (Blueprint $table) {
            $table->dropUnique(['cutting_job_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['version', 'notes']);
            $table->string('status', 16)->change();
        });
    }
};
