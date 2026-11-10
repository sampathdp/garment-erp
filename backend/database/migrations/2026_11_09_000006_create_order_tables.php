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
        // "Job" in client vocabulary. Named `orders` because Laravel's queue owns `jobs`.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique(); // globally unique Job Number
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('page_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32);                       // workflow status (OrderStatus)
            $table->string('status_before_hold', 32)->nullable();
            $table->string('payment_status', 32);               // financial status (OrderPaymentStatus), maintained with verified_paid_minor
            $table->date('required_delivery_date');             // due date, Asia/Colombo calendar date
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->string('urgent_status', 16)->default('not_required');
            $table->char('currency', 3)->default('LKR');
            $table->unsignedInteger('total_quantity')->default(0); // cached sum of order_items.quantity
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);   // includes delivery and tax
            $table->unsignedTinyInteger('advance_percent')->default(30);
            $table->unsignedBigInteger('advance_required_minor')->default(0);
            $table->unsignedBigInteger('verified_paid_minor')->default(0);
            $table->unsignedInteger('version')->default(1);         // optimistic lock
            $table->string('notes', 2000)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'required_delivery_date']);
            $table->index(['page_id', 'status', 'required_delivery_date']);
            $table->index(['branch_id', 'page_id', 'created_at']);
            $table->index(['branch_id', 'payment_status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['sales_owner_id', 'status']);
        });
        CheckConstraint::add('orders', 'chk_orders_advance_pct', 'advance_percent BETWEEN 0 AND 100');
        CheckConstraint::add('orders', 'chk_orders_total', 'total_minor = subtotal_minor - discount_minor + tax_minor + delivery_fee_minor');
        CheckConstraint::add('orders', 'chk_orders_discount', 'discount_minor <= subtotal_minor');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete(); // product category
            $table->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');      // snapshot at submission
            $table->unsignedBigInteger('options_unit_minor')->default(0); // snapshot sum of option surcharges
            $table->unsignedBigInteger('line_total_minor');
            $table->foreignId('price_list_item_id')->nullable()->constrained()->nullOnDelete(); // traceability only
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'sort_order']);
        });
        CheckConstraint::add('order_items', 'chk_oi_qty', 'quantity >= 1');
        CheckConstraint::add('order_items', 'chk_oi_total', 'line_total_minor = quantity * (unit_price_minor + options_unit_minor)');

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_surcharge_minor');  // snapshot
            $table->timestamps();

            $table->unique(['order_item_id', 'product_option_id']);
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete(); // null = system
            $table->timestamp('created_at');

            $table->index(['order_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });

        Schema::create('urgent_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->date('evaluated_delivery_date');
            $table->unsignedSmallInteger('working_days_available');
            $table->timestamp('requested_at');
            $table->string('decision', 16)->nullable(); // approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'requested_at']);
            $table->index(['decision', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('urgent_approvals');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
