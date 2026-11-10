<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 04: job details after payment approval, design workflow (versions, corrections, final
 * files), configurable garment list fields, list confirmation, rule-based page pricing,
 * invoice snapshots and bulk-job eligibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Key/value workflow settings (default designer, correction limit, ...). Values are scalars only.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Additional order information, added by the Page Owner after payment approval.
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('order_type_id')->constrained()->restrictOnDelete(); // fabric type
            $table->string('material_details', 1000)->nullable();
            $table->text('design_instructions')->nullable();
            $table->string('custom_notes', 2000)->nullable();
            $table->string('overall_remarks', 2000)->nullable();
            $table->foreignId('design_attachment_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->timestamp('details_submitted_at')->nullable();
            $table->foreignId('details_submitted_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('design_tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('correction_rounds')->default(0);
            $table->unsignedBigInteger('final_version_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreign('final_version_id')->references('id')->on('design_versions')->restrictOnDelete();
            $table->index('status');
        });

        Schema::table('design_versions', function (Blueprint $table) {
            $table->foreignId('previous_version_id')->nullable()->after('version_no')->constrained('design_versions')->restrictOnDelete();
            // Link only: never fetched by the server (SSRF). Validated against an allow-list of hosts.
            $table->string('external_url', 500)->nullable();
            $table->boolean('is_final')->default(false);
        });

        Schema::table('design_correction_requests', function (Blueprint $table) {
            $table->foreignId('design_task_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('override_reason', 500)->nullable(); // set when the correction limit was exceeded
            $table->index(['design_task_id', 'round_no']);
        });

        // Configurable garment list fields per product category, with option master data.
        Schema::create('garment_option_sets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('garment_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_option_set_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['garment_option_set_id', 'code']);
        });

        Schema::create('garment_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained()->restrictOnDelete();
            $table->string('key', 40);            // player_name and number map to columns; others to values
            $table->string('label', 80);
            $table->string('input_type', 16);     // text | number | select | boolean
            $table->foreignId('garment_option_set_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('affects_price')->default(false);
            $table->boolean('warn_on_duplicate')->default(false);
            $table->unsignedSmallInteger('max_length')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_type_id', 'key']);
        });

        // Sleeve and fit become configurable fields (values table).
        Schema::table('garment_list_items', function (Blueprint $table) {
            $table->dropColumn(['fit', 'sleeve']);
        });

        Schema::create('garment_list_item_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('garment_list_item_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->string('value', 120);

            $table->unique(['garment_list_item_id', 'field_key']);
            $table->index(['field_key', 'value']);
        });

        Schema::table('garment_list_versions', function (Blueprint $table) {
            $table->unsignedInteger('total_quantity')->default(0);
        });

        // Pricing rules: product type × material × branch × quantity tier × attribute conditions.
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropUnique('uq_price_rule'); // conditions make it non-unique; checked in the Action
            $table->foreignId('branch_id')->nullable()->after('material_id')->constrained()->restrictOnDelete();
            $table->string('label', 120)->nullable();
            $table->index(['price_list_id', 'product_type_id']);
        });

        Schema::create('price_list_item_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_item_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 40);
            $table->string('option_code', 40);

            $table->unique(['price_list_item_id', 'field_key']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('related_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('garment_list_version_id')->nullable()->after('price_list_id')->constrained()->restrictOnDelete();
            $table->date('pricing_date')->nullable();
            $table->unsignedTinyInteger('tax_percent')->default(0);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('product_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('price_list_item_id')->nullable()->constrained()->restrictOnDelete();
        });

        // One bulk job per order, created once every prerequisite is met (unique: no duplicates).
        Schema::create('bulk_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // copied from order
            $table->foreignId('design_task_id')->constrained()->restrictOnDelete();
            $table->foreignId('garment_list_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('bulk_designer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 24);
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['bulk_designer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_jobs');
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_item_id');
            $table->dropConstrainedForeignId('product_type_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('garment_list_version_id');
            $table->dropConstrainedForeignId('price_list_id');
            $table->dropColumn(['pricing_date', 'tax_percent']);
        });
        Schema::dropIfExists('price_list_item_conditions');
        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropIndex(['price_list_id', 'product_type_id']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('label');
            $table->unique(['price_list_id', 'product_type_id', 'material_id', 'min_qty'], 'uq_price_rule');
        });
        Schema::table('garment_list_versions', fn (Blueprint $table) => $table->dropColumn('total_quantity'));
        Schema::dropIfExists('garment_list_item_values');
        Schema::table('garment_list_items', function (Blueprint $table) {
            $table->string('fit', 10)->default('unisex');
            $table->string('sleeve', 12)->nullable();
        });
        Schema::dropIfExists('garment_field_definitions');
        Schema::dropIfExists('garment_options');
        Schema::dropIfExists('garment_option_sets');
        Schema::table('design_correction_requests', function (Blueprint $table) {
            $table->dropIndex(['design_task_id', 'round_no']);
            $table->dropConstrainedForeignId('design_task_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['round_no', 'override_reason']);
        });
        Schema::table('design_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_version_id');
            $table->dropColumn(['external_url', 'is_final']);
        });
        Schema::table('design_tasks', function (Blueprint $table) {
            $table->dropForeign(['final_version_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['correction_rounds', 'final_version_id', 'completed_at']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('details_submitted_by');
            $table->dropConstrainedForeignId('design_attachment_document_id');
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn(['material_details', 'design_instructions', 'custom_notes', 'overall_remarks', 'details_submitted_at']);
        });
        Schema::dropIfExists('app_settings');
    }
};
