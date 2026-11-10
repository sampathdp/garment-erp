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
        // A cutting job is also its assignment (internal unit or external supplier).
        Schema::create('cutting_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('print_job_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->foreignId('production_unit_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('supplier_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('gate_pass_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('planned_quantity');
            $table->unsignedInteger('cut_quantity')->default(0);
            $table->string('status', 16); // WorkStatus: pending | in_progress | completed | cancelled
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['order_id', 'order_item_id']);
        });
        CheckConstraint::add('cutting_jobs', 'chk_cj_target', "(mode = 'internal' AND production_unit_id IS NOT NULL AND supplier_id IS NULL) OR (mode = 'external' AND supplier_id IS NOT NULL AND production_unit_id IS NULL)");

        Schema::create('sewing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cutting_job_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->foreignId('production_unit_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('supplier_id')->nullable()->constrained(); // no ON DELETE clause: MySQL forbids referential actions on CHECK columns (InnoDB default = restrict)
            $table->foreignId('gate_pass_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('sewn_quantity')->default(0);
            $table->string('status', 16);
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['order_id', 'order_item_id']);
        });
        CheckConstraint::add('sewing_jobs', 'chk_sj_target', "(mode = 'internal' AND production_unit_id IS NOT NULL AND supplier_id IS NULL) OR (mode = 'external' AND supplier_id IS NOT NULL AND production_unit_id IS NULL)");
        CheckConstraint::add('sewing_jobs', 'chk_sj_qty', 'sewn_quantity <= received_quantity');
    }

    public function down(): void
    {
        Schema::dropIfExists('sewing_jobs');
        Schema::dropIfExists('cutting_jobs');
    }
};
