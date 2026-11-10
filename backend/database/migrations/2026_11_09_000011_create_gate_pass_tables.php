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
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->string('gate_pass_no', 30)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);   // outbound_supplier | return_supplier | delivery | other
            $table->string('status', 16); // draft | approved | checked_out | closed | cancelled
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 500);
            $table->string('vehicle_no', 20)->nullable();
            $table->string('carrier_name', 120)->nullable();
            $table->date('expected_return_date')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'expected_return_date']);
            $table->index(['order_id', 'type']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('gate_pass_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_pass_id')->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity_out');
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->string('unit', 16)->default('pcs');
            $table->string('variance_note', 500)->nullable();
            $table->timestamps();
        });
        CheckConstraint::add('gate_pass_lines', 'chk_gpl_qty', 'quantity_out >= 1 AND quantity_returned <= quantity_out');
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_pass_lines');
        Schema::dropIfExists('gate_passes');
    }
};
