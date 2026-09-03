<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS_SPECS.md §3H, §3R / §4 — Promotion Engine & Loyalty Tables:
 * Promotion Rules, Loyalty Tiers, Gift Cards, Store Credits, Loyalty Accounts, and Ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('POS.pos_promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 25); // simple_discount | buy_x_get_y | bundle | mix_and_match | threshold | time_window | customer_tier | promo_code_passthrough
            $table->string('scope', 15)->default('basket'); // product | category | basket
            $table->string('value_type', 10)->nullable(); // percent | fixed | bundle_price
            $table->decimal('value', 14, 4)->nullable();
            $table->jsonb('constraints')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('stackable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('POS.pos_loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->decimal('points_per_currency_unit', 10, 4)->default(1);
            $table->decimal('tier_threshold', 14, 2)->default(0);
            $table->integer('sort_order')->default(0);
        });

        Schema::create('POS.pos_gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->date('expiry_date')->nullable();
            $table->string('status', 10)->default('active'); // active | redeemed | expired
            $table->timestampTz('issued_at')->useCurrent();
        });

        Schema::create('POS.pos_store_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id'); // informational (CRM.partners)
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('customer_id');
        });

        Schema::create('POS.pos_loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique(); // informational (CRM.partners)
            $table->foreignId('tier_id')->nullable()->constrained('POS.pos_loyalty_tiers');
            $table->decimal('points_balance', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('POS.pos_loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('POS.pos_loyalty_accounts');
            $table->unsignedBigInteger('txn_id')->nullable();
            $table->string('type', 10); // earn | redeem | expire | adjust
            $table->decimal('points_delta', 14, 2);
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('POS.pos_loyalty_ledger');
        Schema::dropIfExists('POS.pos_loyalty_accounts');
        Schema::dropIfExists('POS.pos_store_credits');
        Schema::dropIfExists('POS.pos_gift_cards');
        Schema::dropIfExists('POS.pos_loyalty_tiers');
        Schema::dropIfExists('POS.pos_promotion_rules');
    }
};
