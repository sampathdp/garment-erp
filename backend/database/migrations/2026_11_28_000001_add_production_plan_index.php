<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production plan (ProductionPlanQuery): page owners hold scope.all_branches, so the plan filters
 * orders by status and due date without a branch. The existing (branch_id, status, ...) and
 * (page_id, status, ...) indexes cannot serve that; (status, required_delivery_date) can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'required_delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'required_delivery_date']);
        });
    }
};
