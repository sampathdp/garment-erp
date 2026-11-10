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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique(); // <page prefix>-<year>-<seq>, per page per year
            $table->foreignId('page_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->string('type', 20);   // proforma | advance_receipt | final | credit_note
            $table->string('status', 10); // draft | issued | void
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->char('currency', 3)->default('LKR');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->index(['page_id', 'issued_at']);
            $table->index(['branch_id', 'status', 'issued_at']);
        });
        CheckConstraint::add('invoices', 'chk_invoices_total', 'total_minor = subtotal_minor - discount_minor + tax_minor + delivery_fee_minor');

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });
        CheckConstraint::add('invoice_items', 'chk_ii_total', 'line_total_minor = quantity * unit_price_minor');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
