<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM module — Partners (Contacts §3B / Companies §3C) and their lookup/child tables.
 * Schema CRM already exists per tenant provisioning (App\Jobs\CreateModuleSchemas).
 * Scope: only what §3B/§3C need — Leads/After Sales/Helpdesk/Merge tables (§3D-§3G)
 * are a later build, per CRM_SPECS.md's own suggested build order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CRM.industries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('CRM.partner_role_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('CRM.partners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 12); // individual | organization — app-validated (Request rule)
            $table->foreignId('parent_partner_id')->nullable()->constrained('CRM.partners');
            $table->string('name');
            $table->string('trade_name')->nullable(); // organization only
            $table->string('title_position', 100)->nullable(); // individual only
            $table->string('registration_tax_id', 50)->nullable(); // organization only
            $table->foreignId('industry_id')->nullable()->constrained('CRM.industries');
            $table->json('tags')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('source', 20)->default('manual'); // manual | lead_conversion | import
            $table->boolean('is_active')->default(true);
            $table->foreignId('merged_into_partner_id')->nullable()->constrained('CRM.partners');
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('CRM.addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('CRM.partners')->cascadeOnDelete();
            $table->string('type', 10); // billing | shipping | office | other
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->boolean('is_primary')->default(false);
        });

        Schema::create('CRM.contact_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('CRM.partners')->cascadeOnDelete();
            $table->string('type', 10); // email | phone | mobile | fax
            $table->string('value');
            $table->boolean('is_primary')->default(false);
            $table->boolean('opt_out')->default(false);
        });

        Schema::create('CRM.partner_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('CRM.partners')->cascadeOnDelete();
            $table->foreignId('role_type_id')->constrained('CRM.partner_role_types');
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->boolean('is_active')->default(true);

            $table->index(['partner_id', 'is_active']);
        });

        // Partial unique indexes — no plain UNIQUE, since a role/primary-flag can be
        // reassigned over time (history, §4) and Laravel's Blueprint has no `->where()`
        // for indexes, so these are raw statements (same as CRM_SPECS.sql).
        DB::statement('CREATE UNIQUE INDEX uq_crm_addresses_primary ON "CRM".addresses (partner_id, type) WHERE is_primary');
        DB::statement('CREATE UNIQUE INDEX uq_crm_contact_points_primary ON "CRM".contact_points (partner_id, type) WHERE is_primary');
        DB::statement('CREATE UNIQUE INDEX uq_crm_partner_roles_active ON "CRM".partner_roles (partner_id, role_type_id) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('CRM.partner_roles');
        Schema::dropIfExists('CRM.contact_points');
        Schema::dropIfExists('CRM.addresses');
        Schema::dropIfExists('CRM.partners');
        Schema::dropIfExists('CRM.partner_role_types');
        Schema::dropIfExists('CRM.industries');
    }
};
