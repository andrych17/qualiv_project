<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3G Fixed Assets: asset register, dual commercial/fiscal
 * depreciation schedules, disposal.
 *
 * `fa_asset_groups` is the tenant-editable Indonesian tax classification (Kelompok 1-4 +
 * Bangunan Permanen/Non-Permanen) that carries the PMK-regulated FISCAL rates — never
 * hardcoded in application logic (§3G rule), since regulation changes are a data edit via
 * this table, not a deploy. `AssetGroupService::seedStarterGroups()` seeds current defaults
 * per company, same shape as `AccountService::seedStarterCoa()`.
 *
 * Two separate schedule tables (commercial/fiscal), one row PER ASSET PER FISCAL PERIOD,
 * generated as `DepreciationRunService::runForPeriod()` actually executes — not
 * pre-computed for an asset's whole life at acquisition. A pre-computed full schedule goes
 * silently stale the moment a rate table or method is edited mid-life; a per-period row
 * generated on run always reflects whatever rule was in force at the time it posted, and
 * "re-run month 5" has an obvious meaning (only newly-added assets get a new row — assets
 * already scheduled for that period are untouched, enforced by the unique constraint below,
 * not just a query-then-insert check).
 *
 * `fa_depreciation_schedule_fiscal` has no `journal_id` — §3G is explicit that fiscal
 * depreciation is a parallel schedule for SPT Tahunan reconciliation, never posted to the
 * commercial GL (that would double-book depreciation across two different rate regimes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.fa_asset_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('code', 30); // e.g. KELOMPOK_1, BANGUNAN_NON_PERMANEN
            $table->string('name', 100);
            $table->boolean('is_building')->default(false); // buildings: fiscal method is straight-line only, enforced in FixedAssetService
            $table->unsignedSmallInteger('fiscal_useful_life_months');
            $table->decimal('fiscal_straight_line_rate', 6, 4); // annual rate, e.g. 0.2500
            $table->decimal('fiscal_declining_rate', 6, 4)->nullable(); // null for building groups — declining-balance isn't a valid fiscal election for buildings
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('ACCOUNTING.fa_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('asset_group_id')->constrained('ACCOUNTING.fa_asset_groups');
            $table->string('asset_no', 40);
            $table->string('name', 150);
            $table->foreignId('vendor_partner_id')->nullable()->constrained('CRM.partners');
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 18, 2);
            $table->foreignId('asset_gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('accumulated_depreciation_gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('depreciation_expense_gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->unsignedSmallInteger('commercial_useful_life_months');
            $table->string('commercial_method', 20); // straight_line|declining_balance
            $table->decimal('commercial_declining_rate', 6, 4)->nullable(); // annual rate — required only when commercial_method = declining_balance; a business choice, not regulated, so it's entered explicitly rather than derived from a convention
            $table->string('fiscal_method', 20); // straight_line|declining_balance — may differ from commercial_method; a taxpayer elects each independently
            $table->string('subject_type', 100)->nullable(); // most commonly accounting.ap_bills, for traceability to the bill that created it (§3G rule) — no hard FK, same polymorphic seam as elsewhere
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 15)->default('active'); // active|disposed
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'asset_no']);
            $table->index(['company_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.fa_depreciation_schedule_commercial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('ACCOUNTING.fa_assets');
            $table->foreignId('fiscal_period_id')->constrained('ACCOUNTING.fiscal_periods');
            $table->decimal('depreciation_amount', 18, 2);
            $table->decimal('accumulated_depreciation', 18, 2); // running total through this row
            $table->decimal('net_book_value', 18, 2); // acquisition_cost - accumulated_depreciation, after this row
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['asset_id', 'fiscal_period_id']); // the idempotency guarantee — enforced at the DB, not just query-then-insert
        });

        Schema::create('ACCOUNTING.fa_depreciation_schedule_fiscal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('ACCOUNTING.fa_assets');
            $table->foreignId('fiscal_period_id')->constrained('ACCOUNTING.fiscal_periods');
            $table->decimal('depreciation_amount', 18, 2);
            $table->decimal('accumulated_depreciation', 18, 2);
            $table->decimal('net_book_value', 18, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['asset_id', 'fiscal_period_id']);
        });

        Schema::create('ACCOUNTING.fa_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->unique()->constrained('ACCOUNTING.fa_assets'); // one disposal per asset
            $table->date('disposal_date');
            $table->decimal('proceeds', 18, 2)->default(0); // 0 = pure write-off
            $table->foreignId('proceeds_gl_account_id')->nullable()->constrained('ACCOUNTING.accounts'); // required only when proceeds > 0
            $table->foreignId('gain_loss_gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->decimal('commercial_nbv_at_disposal', 18, 2);
            $table->decimal('fiscal_nbv_at_disposal', 18, 2);
            $table->decimal('gain_loss_amount', 18, 2); // proceeds - commercial_nbv_at_disposal; positive = gain
            $table->string('notes', 255)->nullable();
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.fa_disposals');
        Schema::dropIfExists('ACCOUNTING.fa_depreciation_schedule_fiscal');
        Schema::dropIfExists('ACCOUNTING.fa_depreciation_schedule_commercial');
        Schema::dropIfExists('ACCOUNTING.fa_assets');
        Schema::dropIfExists('ACCOUNTING.fa_asset_groups');
    }
};
