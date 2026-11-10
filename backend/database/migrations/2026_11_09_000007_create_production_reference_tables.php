<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Internal teams / departments that do production work (printing, cutting, sewing ...).
        Schema::create('production_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('department', 32); // ProductionDepartment
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'name']);
            $table->index(['branch_id', 'department', 'is_active']);
        });

        // External producers (company-wide).
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone_e164', 16)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('service_type', 32); // printing | sewing | cutting | full
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_type', 'is_active']);
        });

        Schema::create('measurement_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 80);
            $table->string('unit', 8)->default('cm');
            $table->timestamps();

            $table->unique(['product_type_id', 'code']);
        });

        Schema::create('size_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('size_id')->constrained()->restrictOnDelete();
            $table->foreignId('measurement_point_id')->constrained()->restrictOnDelete();
            $table->decimal('target_value', 7, 2);
            $table->decimal('tolerance', 5, 2);
            $table->timestamps();

            $table->unique(['product_type_id', 'size_id', 'measurement_point_id'], 'uq_size_spec');
        });

        // Versioned checklist templates shared by bulk, printing, cutting, sewing, QC and dispatch stages.
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('stage', 32);
            $table->foreignId('product_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['stage', 'product_type_id', 'name', 'version_no'], 'uq_checklist_template');
            $table->index(['stage', 'is_active']);
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('label', 255);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('defect_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_types');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('size_specs');
        Schema::dropIfExists('measurement_points');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('production_units');
    }
};
