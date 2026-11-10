<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Design task per order item.
        Schema::create('design_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->string('status', 32);
            $table->foreignId('designer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('approved_version_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique('order_item_id');
            $table->index(['designer_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // Immutable after submission.
        Schema::create('design_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_task_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('notes', 2000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['design_task_id', 'version_no']);
        });

        Schema::table('design_tasks', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('design_versions')->restrictOnDelete();
            $table->foreign('approved_version_id')->references('id')->on('design_versions')->restrictOnDelete();
        });

        Schema::create('design_version_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16); // source | preview | mockup
            $table->timestamp('created_at')->nullable();

            $table->unique(['design_version_id', 'document_id']);
        });

        // Customer decision recorded by staff (customers do not log in).
        Schema::create('design_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_version_id')->constrained()->restrictOnDelete();
            $table->string('decision', 20);  // approved | changes_requested
            $table->string('channel', 20);   // ApprovalChannel
            $table->string('customer_comment', 2000)->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['design_version_id', 'decided_at']);
        });

        Schema::create('design_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_review_id')->constrained()->restrictOnDelete();
            $table->string('description', 1000);
            $table->foreignId('resolved_in_version_id')->nullable()->constrained('design_versions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_correction_requests');
        Schema::dropIfExists('design_reviews');
        Schema::dropIfExists('design_version_files');
        Schema::table('design_tasks', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
            $table->dropForeign(['approved_version_id']);
        });
        Schema::dropIfExists('design_versions');
        Schema::dropIfExists('design_tasks');
    }
};
