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
        // Verification is a single decision per payment, so it lives on the row
        // (verified_by/at or rejection). A rejected payment is final; a corrected slip is a new payment.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('LKR');
            $table->string('method', 16);  // bank_transfer | cash | card | online | cheque | cod
            $table->string('status', 24);  // pending_verification | verified | rejected | reversed
            $table->string('reference_no', 80)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('slip_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete(); // COD collections
            $table->foreignId('recorded_by')->constrained('users'); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('verified_by')->nullable()->constrained('users'); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['method', 'reference_no']);
        });
        CheckConstraint::add('payments', 'chk_payments_amount', 'amount_minor > 0');
        // Separation of duties: the recorder can never verify their own payment.
        CheckConstraint::add('payments', 'chk_payments_sod', 'verified_by IS NULL OR verified_by <> recorded_by');

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->string('method', 16);
            $table->string('reference_no', 80)->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->index('payment_id');
        });
        CheckConstraint::add('refunds', 'chk_refunds_amount', 'amount_minor > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
    }
};
