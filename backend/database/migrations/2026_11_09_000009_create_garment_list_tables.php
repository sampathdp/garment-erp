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
        // One name/number list per order item; content lives in versions.
        Schema::create('garment_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('version')->default(1); // optimistic lock
            $table->timestamps();
        });

        Schema::create('garment_list_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_list_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('status', 16); // draft | locked | superseded
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['garment_list_id', 'version_no']);
        });

        Schema::table('garment_lists', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('garment_list_versions')->restrictOnDelete();
        });

        Schema::create('garment_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_list_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('player_name', 60)->nullable();
            $table->string('number', 5)->nullable(); // string: "00" != "0"
            $table->foreignId('size_id')->constrained()->restrictOnDelete();
            $table->string('fit', 10);      // male | female | kids | unisex
            $table->string('sleeve', 12)->nullable(); // short | long | sleeveless
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['garment_list_version_id', 'line_no']);
            $table->index(['garment_list_version_id', 'number']);
            $table->index(['garment_list_version_id', 'size_id']);
        });
        CheckConstraint::add('garment_list_items', 'chk_gli_qty', 'quantity >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('garment_list_items');
        Schema::table('garment_lists', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('garment_list_versions');
        Schema::dropIfExists('garment_lists');
    }
};
