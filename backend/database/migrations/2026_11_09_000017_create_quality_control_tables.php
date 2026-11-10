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
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('stage', 16); // post_print | post_cut | post_sew | final
            $table->unsignedInteger('quantity_inspected');
            $table->unsignedInteger('quantity_passed');
            $table->unsignedInteger('quantity_failed');
            $table->string('result', 8); // pass | fail | partial
            $table->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->index(['order_id', 'stage']);
            $table->index(['branch_id', 'result', 'inspected_at']);
        });
        CheckConstraint::add('qc_inspections', 'chk_qc_qty', 'quantity_passed + quantity_failed = quantity_inspected');

        Schema::create('qc_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspection_id')->constrained()->restrictOnDelete();
            $table->foreignId('defect_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('garment_list_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 10); // reprint | recut | resew | repair | scrap | accept
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
        CheckConstraint::add('qc_defects', 'chk_qcd_qty', 'quantity >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_defects');
        Schema::dropIfExists('qc_inspections');
    }
};
