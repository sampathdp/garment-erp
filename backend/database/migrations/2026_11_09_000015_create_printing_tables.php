<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->foreignId('design_version_id')->constrained()->restrictOnDelete();      // must be the approved version
            $table->foreignId('garment_list_version_id')->constrained()->restrictOnDelete(); // must be locked
            $table->string('mode', 10); // internal | external
            $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('gate_pass_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16); // preparing | sample_review | printing | printed | invalidated
            $table->unsignedInteger('printed_quantity')->default(0);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // Sample approval is recorded on the sample row (one decision per sample).
        Schema::create('print_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_job_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sample_no');
            $table->foreignId('photo_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('decision', 10)->nullable(); // approved | rejected
            $table->string('channel', 20)->nullable();
            $table->string('customer_comment', 2000)->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['print_job_id', 'sample_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_samples');
        Schema::dropIfExists('print_jobs');
    }
};
