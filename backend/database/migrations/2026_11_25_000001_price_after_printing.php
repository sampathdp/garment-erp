<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price after printing (client decision 2026-11-25):
 *  - the order form no longer has a unit price; staff enter a fixed advance amount, which must be
 *    at least the page's minimum advance (pages.min_advance_minor);
 *  - the invoice is issued when physical printing is completed, priced on the printed quantities,
 *    so a bulk job is created before any invoice exists (bulk_jobs.invoice_id becomes nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->unsignedBigInteger('min_advance_minor')->default(0)->after('default_delivery_fee_minor');
        });

        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bulk_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable(false)->change();
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('min_advance_minor');
        });
    }
};
