<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CheckConstraint::drop('orders', 'chk_orders_total');
        CheckConstraint::drop('orders', 'chk_orders_discount');

        CheckConstraint::add(
            'orders',
            'chk_orders_total',
            '((subtotal_minor = 0 AND total_minor = 0 AND tax_minor = 0) OR (total_minor + discount_minor = subtotal_minor + tax_minor + delivery_fee_minor))',
        );
        CheckConstraint::add('orders', 'chk_orders_discount', 'subtotal_minor = 0 OR discount_minor <= subtotal_minor');
    }

    public function down(): void
    {
        CheckConstraint::drop('orders', 'chk_orders_total');
        CheckConstraint::drop('orders', 'chk_orders_discount');

        CheckConstraint::add(
            'orders',
            'chk_orders_total',
            'total_minor = subtotal_minor - discount_minor + tax_minor + delivery_fee_minor',
        );
        CheckConstraint::add('orders', 'chk_orders_discount', 'discount_minor <= subtotal_minor');
    }
};
