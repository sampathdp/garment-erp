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
        // Single company (client decision). The row holds legal/invoice details; no tenant scoping.
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_registration_no', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone_e164', 16)->nullable();
            $table->string('email', 191)->nullable();
            $table->char('default_currency', 3)->default('LKR');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('address', 500)->nullable();
            $table->string('phone_e164', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Working week used by the urgent (< 7 working days) rule.
        Schema::create('working_calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('iso_weekday'); // 1 = Monday ... 7 = Sunday
            $table->boolean('is_working_day');
            $table->timestamps();

            $table->unique(['organization_id', 'iso_weekday']);
        });
        CheckConstraint::add('working_calendar_days', 'chk_wcd_weekday', 'iso_weekday BETWEEN 1 AND 7');

        // branch_id NULL = company-wide holiday.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('holiday_date');
            $table->string('name', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'holiday_date']);
            $table->index('holiday_date');
        });

        // Gap-free human-readable numbers (orders, invoices, gate passes).
        // Always read with SELECT ... FOR UPDATE inside the inserting transaction.
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->primary(['scope_type', 'scope_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('working_calendar_days');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organizations');
    }
};
