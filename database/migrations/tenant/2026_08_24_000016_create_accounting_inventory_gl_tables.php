<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3H Inventory GL Posting — the financial-side-only interface engine. No costing, no
 * physical stock: Inventory (`INVENTORY_SPECS.md`) remains the sole source of truth for
 * quantity and valuation; this only translates an already-finalized Inventory movement into
 * a GL journal.
 *
 * `inventory_item_id`/`inventory_category_id` (on both tables below) are deliberately NOT
 * foreign keys — they're soft references to `App\Modules\Inventory\Models\Product::id` /
 * `ProductCategory::id` (`INVENTORY.products` / `INVENTORY.product_categories`, a different
 * tenant schema), the same soft-reference discipline every other cross-module pointer in
 * this platform already uses (subject_type/subject_id, `gl_journals.subject_id`, etc.) —
 * existence is validated in PHP (InventoryGlMappingService), not the DB. (Earlier revisions
 * of this comment pointed at the legacy public-schema `inventory_items`/`inventory_categories`
 * demo tables CLAUDE.md §7A flags, from before Inventory's real engine shipped — that gap is
 * closed: InventoryGlPostingService::resolveMapping() now reads `Product::category_id`.)
 *
 * InventoryGoodsReceived/GoodsIssued/StockAdjusted (App\Modules\Accounting\Events) are the
 * seam Inventory's Goods Receipt/Issue/Adjustment engines (`INVENTORY_SPECS.md` §3D/§3E/§3G)
 * dispatch into on post(), carrying that same Product::id.
 *
 * inventory_gl_mappings has no composite unique on (company_id, inventory_item_id,
 * inventory_category_id) for the same reason budget_lines doesn't: both id columns are
 * nullable (exactly one is set per row — item-level overrides the category-level default),
 * and Postgres treats every NULL as distinct under a unique index, so it wouldn't actually
 * prevent two mapping rows for the same item. InventoryGlMappingService::save() upserts by
 * scope instead (delete-then-recreate), the same service-level invariant already used there.
 *
 * inventory_gl_postings.unique(subject_type, subject_id) is the idempotency guard — a
 * replayed/retried event (queue retry, or a manual Retry from the failure queue) must never
 * double-post the same Inventory stock_ledger row, same discipline as §3I's allocation_runs
 * and §3P's recurring_generation_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->foreignId('inventory_control_account_id')->nullable()->after('ap_control_account_id')->constrained('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.inventory_gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('inventory_category_id')->nullable();
            $table->foreignId('inventory_asset_account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('cogs_account_id')->nullable()->constrained('ACCOUNTING.accounts');
            $table->foreignId('grni_account_id')->nullable()->constrained('ACCOUNTING.accounts');
            $table->foreignId('adjustment_account_id')->nullable()->constrained('ACCOUNTING.accounts');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'inventory_item_id']);
            $table->index(['company_id', 'inventory_category_id']);
        });

        Schema::create('ACCOUNTING.inventory_gl_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('event_type', 20); // goods_received|goods_issued|stock_adjusted
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('subject_type', 60);
            $table->string('subject_id', 60);
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.inventory_posting_failures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('event_type', 20);
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('subject_type', 60);
            $table->string('subject_id', 60);
            $table->json('payload'); // the original event's data, so Retry can replay it
            $table->string('reason', 255);
            $table->string('status', 20)->default('pending'); // pending|resolved
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.inventory_posting_failures');
        Schema::dropIfExists('ACCOUNTING.inventory_gl_postings');
        Schema::dropIfExists('ACCOUNTING.inventory_gl_mappings');
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_control_account_id');
        });
    }
};
