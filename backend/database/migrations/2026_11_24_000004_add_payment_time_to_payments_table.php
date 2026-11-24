<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time of the bank transfer / deposit (Asia/Colombo wall-clock, minutes). Together with the
 * amount, bank and payment date it identifies a duplicate payment entered on another page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->time('payment_time')->nullable()->after('paid_at');
            $table->index(['amount_minor', 'paid_at', 'payment_time', 'bank_name'], 'payments_duplicate_match_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_duplicate_match_index');
            $table->dropColumn('payment_time');
        });
    }
};
