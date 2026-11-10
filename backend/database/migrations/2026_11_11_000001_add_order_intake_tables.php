<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 03: customers with WhatsApp and page/branch association, configurable order types,
 * dual urgent approval (Second Admin + Operation Admin) and payment verification history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('whatsapp_e164', 16)->nullable()->after('phone_e164');
            // Page the customer contacted (Facebook page) and the branch of their first order.
            $table->foreignId('page_id')->nullable()->after('customer_code')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->after('page_id')->constrained()->restrictOnDelete();

            // Duplicate detection and search are always page-scoped.
            $table->index(['page_id', 'phone_e164']);
            $table->index(['page_id', 'whatsapp_e164']);
            $table->index(['page_id', 'name']);
        });

        // Configurable order types ("Single Order", "Bulk Order", ...).
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Nullable only for rows created before Phase 03; the API always requires it.
            $table->foreignId('order_type_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->index(['status', 'created_at']);
        });

        // Job numbers keep the branch and page codes ("CMB-JRSY-26-00042"), so 30 characters are not enough.
        // SQLite does not enforce VARCHAR lengths, so the column is only widened on MySQL/MariaDB.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('order_no', 60)->change());
        }

        Schema::table('urgent_approvals', function (Blueprint $table) {
            // Business date the rule was evaluated from (submission or due-date change), Asia/Colombo.
            $table->date('reference_date')->nullable()->after('evaluated_delivery_date');
            // A due-date change replaces the open request; the old one is kept as history.
            $table->timestamp('superseded_at')->nullable()->after('decided_at');
        });

        // One decision per approval slot per request. Both slots must approve; any rejection rejects.
        Schema::create('urgent_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('urgent_approval_id')->constrained()->restrictOnDelete();
            $table->string('slot', 24);       // second_admin | operation_admin
            $table->string('decision', 16);   // approved | rejected
            $table->string('reason', 500)->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['urgent_approval_id', 'slot']);
            $table->unique(['urgent_approval_id', 'decided_by']); // two different people
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('verification_notes', 500)->nullable()->after('rejection_reason');
        });

        // Append-only verification history (submitted, verified, rejected).
        Schema::create('payment_verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('action', 24);
            $table->string('notes', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_verification_events');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('verification_notes'));
        Schema::dropIfExists('urgent_approval_decisions');
        Schema::table('urgent_approvals', fn (Blueprint $table) => $table->dropColumn(['reference_date', 'superseded_at']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('order_type_id');
        });
        Schema::dropIfExists('order_types');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'phone_e164']);
            $table->dropIndex(['page_id', 'whatsapp_e164']);
            $table->dropIndex(['page_id', 'name']);
            $table->dropConstrainedForeignId('page_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('whatsapp_e164');
        });
    }
};
