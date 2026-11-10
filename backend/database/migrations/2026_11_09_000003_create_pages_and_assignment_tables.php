<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A page is a Facebook page (client decision): sales channel with its own pricing and invoice identity.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('platform', 20)->default('facebook');
            $table->string('external_url', 255)->nullable();
            $table->string('invoice_prefix', 10)->unique();
            $table->string('invoice_header_name', 150);
            $table->string('invoice_address', 500)->nullable();
            $table->string('invoice_phone_e164', 16)->nullable();
            $table->foreignId('logo_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Data scoping: a user sees records of assigned branches (unless scope.all_branches)
        // and of assigned pages (unless scope.all_pages). Deny by default.
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['branch_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('page_user', function (Blueprint $table) {
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['page_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_user');
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('pages');
    }
};
