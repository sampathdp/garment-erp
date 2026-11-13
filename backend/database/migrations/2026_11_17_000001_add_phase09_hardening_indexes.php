<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 09 database hardening. Every index here was measured with ANALYZE on a volume copy
 * (200k orders, 300k payments, 500k notifications; DATABASE_DESIGN.md §8):
 *
 *  - payments.page_id: denormalised from the order (an order's page never changes), like
 *    branch_id. Page-scoped users (Page Owner, Second Admin) were filtered through
 *    `order_id IN (SELECT id FROM orders WHERE page_id IN …)`, which MySQL/MariaDB run as a
 *    semi-join driven by orders: the verification queue took 7.4 s and the payment list 11.6 s.
 *    With page_id on the row both take < 3 ms.
 *  - payments(created_at), payments(status, created_at): default sort and the verification
 *    queue without a full index scan + filesort (3.3 s → 0.1 ms).
 *  - orders(created_at): default "newest first" order list for every scope (208 ms → 0.3 ms;
 *    page 101 4.1 s → 27 ms).
 *  - notifications(notifiable, created_at): history and the 20-second poll (256 ms → < 1 ms);
 *    replaces the redundant (notifiable_type, notifiable_id) index (a prefix of the unread index).
 *  - deliveries_order_id_index duplicated deliveries_order_id_unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
        });

        DB::table('payments')->whereNull('page_id')->orderBy('id')->chunkById(1000, function ($payments): void {
            $pages = DB::table('orders')->whereIn('id', $payments->pluck('order_id')->unique()->all())->pluck('page_id', 'id');
            foreach ($payments as $payment) {
                DB::table('payments')->where('id', $payment->id)->update(['page_id' => $pages[$payment->order_id]]);
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable(false)->change();
            $table->index('created_at');
            $table->index(['status', 'created_at']);
            $table->index(['page_id', 'status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_at_index');
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['order_id']); // deliveries_order_id_unique serves the FK and every lookup
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index('order_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id']);
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        // The foreign key first: MySQL/MariaDB dropped the FK's own index when (page_id, status,
        // created_at) was added, so that composite index now backs the FK and cannot go before it.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['page_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'status', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('page_id');
        });
    }
};
