<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order types were removed from the system (2026-11-24 client decision). The column and the
 * reference table are dropped; down() restores the empty structure only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_type_id');
        });
        Schema::dropIfExists('order_types');
    }

    public function down(): void
    {
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('order_type_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
        });
    }
};
