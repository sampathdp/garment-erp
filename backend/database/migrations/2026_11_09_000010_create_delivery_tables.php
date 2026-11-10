<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order, never changed
            $table->string('method', 16);  // pickup | courier | own_vehicle
            $table->string('status', 16);  // scheduled | dispatched | delivered | failed
            $table->string('courier_name', 80)->nullable();
            $table->string('tracking_no', 60)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->unsignedBigInteger('cod_amount_minor')->default(0); // outstanding balance at dispatch
            $table->unsignedBigInteger('cod_collected_minor')->nullable();
            $table->string('cod_status', 16)->default('not_applicable');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('received_by_name', 120)->nullable();
            $table->foreignId('proof_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'scheduled_date']);
            $table->index(['branch_id', 'cod_status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
