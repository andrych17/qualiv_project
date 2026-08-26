<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE module — §3B/§3I/§3F master & config: spend categories, cost centers
 * (with optional Accounting cost-center mapping, §4/§5), catalog items, soft budgets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 10); // direct|indirect
            $table->string('capex_opex', 10)->default('opex'); // capex|opex
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PURCHASE.cost_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            // informational — not enforced FK, Accounting is an optional install (§4/§5)
            $table->unsignedBigInteger('accounting_cost_center_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 40)->unique();
            $table->string('description', 255);
            $table->foreignId('category_id')->nullable()->constrained('PURCHASE.categories');
            $table->string('unit', 20)->default('unit');
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('CRM.partners');
            $table->decimal('negotiated_price', 18, 2)->nullable();
            $table->date('price_valid_from')->nullable();
            $table->date('price_valid_to')->nullable();
            $table->string('source', 10)->default('manual'); // manual|rfx_award
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7); // YYYY-MM
            $table->foreignId('cost_center_id')->constrained('PURCHASE.cost_centers');
            $table->foreignId('category_id')->constrained('PURCHASE.categories');
            $table->decimal('budget_amount', 18, 2);
            $table->decimal('committed_amount', 18, 2)->default(0); // rolled up from PR/PO
            $table->decimal('actual_amount', 18, 2)->default(0); // rolled up from matched invoices
            $table->timestamps();

            $table->unique(['period', 'cost_center_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_budgets');
        Schema::dropIfExists('PURCHASE.pur_catalog_items');
        Schema::dropIfExists('PURCHASE.cost_centers');
        Schema::dropIfExists('PURCHASE.categories');
    }
};
