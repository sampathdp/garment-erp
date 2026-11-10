<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metadata for files on the private disk. Owners reference documents via FK
        // (no polymorphic owner columns), so referential integrity is enforced by the DB.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('category', 32);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('scan_status', 16)->default('skipped');
            $table->timestamp('created_at')->nullable();
            $table->softDeletes();

            $table->index(['branch_id', 'category', 'created_at']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
