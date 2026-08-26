<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3B/§4 Master / lookup / config tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.territories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('SALES.sales_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('territory_id')->nullable()->constrained('SALES.territories');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('SALES.sales_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_team_id')->constrained('SALES.sales_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 10)->default('member'); // lead|member
            $table->timestampTz('joined_at')->useCurrent();

            $table->unique(['sales_team_id', 'user_id']);
        });

        Schema::create('SALES.price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('currency', 3)->default('IDR');
            $table->foreignId('territory_id')->nullable()->constrained('SALES.territories');
            $table->string('customer_segment', 50)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_tenant_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('SALES.price_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('SALES.price_lists')->cascadeOnDelete();
            $table->string('item_type', 10)->default('service'); // product|service
            // Informational reference to INVENTORY.products.id when installed
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('description', 255);
            $table->decimal('unit_price', 14, 2);
            $table->timestamps();

            $table->index('price_list_id');
        });

        Schema::create('SALES.promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('discount_type', 10); // percentage|fixed
            $table->decimal('discount_value', 14, 2);
            $table->date('valid_from');
            $table->date('valid_to');
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('SALES.commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('basis', 10); // flat_pct|tiered
            $table->decimal('flat_rate_pct', 5, 2)->nullable();
            $table->jsonb('tier_rules')->nullable(); // [{min_revenue, max_revenue, rate_pct}, ...]
            $table->string('applies_to_type', 10); // team|rep
            $table->foreignId('applies_to_sales_team_id')->nullable()->constrained('SALES.sales_teams');
            $table->foreignId('applies_to_user_id')->nullable()->constrained('users');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('SALES.customer_sales_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('CRM.partners');
            $table->foreignId('sales_team_id')->nullable()->constrained('SALES.sales_teams');
            $table->foreignId('price_list_id')->nullable()->constrained('SALES.price_lists');
            $table->foreignId('assigned_rep_id')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('SALES.customer_credit_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('CRM.partners');
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->integer('payment_terms_days')->default(30);
            $table->boolean('on_hold')->default(false);
            $table->timestamps();
        });

        Schema::create('SALES.sales_portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.sales_portal_tokens');
        Schema::dropIfExists('SALES.customer_credit_profiles');
        Schema::dropIfExists('SALES.customer_sales_profiles');
        Schema::dropIfExists('SALES.commission_plans');
        Schema::dropIfExists('SALES.promo_codes');
        Schema::dropIfExists('SALES.price_list_lines');
        Schema::dropIfExists('SALES.price_lists');
        Schema::dropIfExists('SALES.sales_team_members');
        Schema::dropIfExists('SALES.sales_teams');
        Schema::dropIfExists('SALES.territories');
    }
};
