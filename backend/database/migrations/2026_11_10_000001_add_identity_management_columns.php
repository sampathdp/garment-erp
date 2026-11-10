<?php

declare(strict_types=1);

use App\Support\Database\CheckConstraint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02: user/session management, configurable roles, page details and bulk designer assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Incremented to revoke every session of the user (deactivation, password change/reset).
            // Driver-independent: works with database and Redis sessions alike.
            $table->unsignedInteger('session_version')->default(1)->after('remember_token');
            $table->timestamp('password_changed_at')->nullable()->after('session_version');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
            // Seeded roles: cannot be renamed or deleted. super_admin is fully locked.
            $table->boolean('is_system')->default(false)->after('description');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->string('contact_email', 191)->nullable()->after('external_url');
            $table->string('contact_phone_e164', 16)->nullable()->after('contact_email');
            $table->string('address', 500)->nullable()->after('contact_phone_e164');
            // Pricing configuration (defaults for new orders of the page; price lists come in Phase 03).
            $table->char('currency', 3)->default('LKR')->after('address');
            $table->unsignedTinyInteger('default_advance_percent')->default(30)->after('currency');
            $table->unsignedBigInteger('default_delivery_fee_minor')->default(0)->after('default_advance_percent');
        });
        CheckConstraint::add('pages', 'chk_pages_advance', 'default_advance_percent BETWEEN 30 AND 100');

        Schema::table('page_user', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('user_id');
            $table->index(['page_id', 'is_owner']);
        });

        Schema::table('bulk_assignments', function (Blueprint $table) {
            // The (internal or external) bulk designer who works on this assignment.
            $table->foreignId('bulk_designer_id')->nullable()->after('supplier_id')->constrained('users')->restrictOnDelete();
            $table->index(['bulk_designer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bulk_assignments', function (Blueprint $table) {
            $table->dropIndex(['bulk_designer_id', 'status']);
            $table->dropConstrainedForeignId('bulk_designer_id');
        });

        Schema::table('page_user', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'is_owner']);
            $table->dropColumn('is_owner');
        });

        CheckConstraint::drop('pages', 'chk_pages_advance');
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone_e164', 'address', 'currency', 'default_advance_percent', 'default_delivery_fee_minor']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'description', 'is_system']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['session_version', 'password_changed_at', 'deactivated_at']);
        });
    }
};
