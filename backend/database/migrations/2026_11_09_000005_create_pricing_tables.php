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
        Schema::create('size_charts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_chart_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('label', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['size_chart_id', 'code']);
        });

        // Product category (jersey, shorts, t-shirt ...).
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->foreignId('size_chart_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Fabric / material master data.
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_type_id', 'code']);
        });

        // Page-specific price lists (pricing tables).
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->char('currency', 3)->default('LKR');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['page_id', 'is_active', 'valid_from']);
        });

        // Pricing rules: product type x material (optional) x quantity tier.
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty')->nullable();
            $table->unsignedBigInteger('unit_price_minor');
            $table->timestamps();

            $table->unique(['price_list_id', 'product_type_id', 'material_id', 'min_qty'], 'uq_price_rule');
        });
        CheckConstraint::add('price_list_items', 'chk_pli_qty', 'min_qty >= 1 AND (max_qty IS NULL OR max_qty >= min_qty)');

        Schema::create('price_list_option_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_surcharge_minor');
            $table->timestamps();

            $table->unique(['price_list_id', 'product_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_option_prices');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('product_types');
        Schema::dropIfExists('sizes');
        Schema::dropIfExists('size_charts');
    }
};
