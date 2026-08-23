<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3B Chart of Accounts & GL Setup: companies, COA, fiscal
 * calendar, cost centers, plus the currencies lookup that §3C's gl_journals
 * FKs into (full multi-currency, §3L, is a later build — this table only
 * exists early because the FK dependency forces it).
 *
 * Status/type values are app-validated (varchar + constants on the model), not
 * DB CHECK constraints — matches the convention already shipped in WNE/CRM's
 * migrations rather than ACCOUNTING_SPECS.sql's CHECK-constrained reference schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.companies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 255);
            $table->string('npwp', 30)->nullable();
            $table->text('address')->nullable();
            $table->char('base_currency', 3)->default('IDR'); // fixed at setup (§3L)
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('coa_template_code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ACCOUNTING.currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary(); // ISO 4217
            $table->string('name', 50);
            $table->boolean('is_enabled')->default(true);
        });

        // §3B COA. Self-referencing parent_account_id added in a follow-up
        // Schema::table below — a table can't FK to itself inside the same
        // Schema::create call.
        Schema::create('ACCOUNTING.accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('account_code', 20); // 1xxxx Aset .. 6xxxx Beban (§3B numbering convention)
            $table->string('account_name', 150);
            $table->string('account_type', 10); // asset|liability|equity|revenue|cogs|expense
            $table->string('normal_balance', 6); // debit|credit
            $table->unsignedBigInteger('parent_account_id')->nullable();
            // Control accounts (AR/AP/Inventory) reject direct manual journal lines —
            // enforced in JournalService, not just here (§3B rule).
            $table->boolean('is_control_account')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'account_code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('ACCOUNTING.accounts', function (Blueprint $table) {
            $table->foreign('parent_account_id')->references('id')->on('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->smallInteger('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 15)->default('open'); // open|soft_closed|hard_closed

            $table->unique(['company_id', 'year']);
        });

        Schema::create('ACCOUNTING.fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('fiscal_year_id')->constrained('ACCOUNTING.fiscal_years')->cascadeOnDelete();
            $table->unsignedTinyInteger('period_no'); // 1..12
            $table->date('start_date');
            $table->date('end_date');
            // Posting gate for §3C: hard_closed blocks all posting, soft_closed
            // blocks ordinary posting (elevated-approval exception — deferred, see
            // JournalService docblock), open allows normal posting.
            $table->string('status', 15)->default('open');

            $table->unique(['company_id', 'fiscal_year_id', 'period_no']);
            $table->index(['company_id', 'status']);
        });

        // Canonical financial cost-center dimension (§3B/§3I) — Purchase/HCM
        // optionally map into this list rather than maintaining their own.
        Schema::create('ACCOUNTING.cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('parent_cost_center_id')->nullable()->constrained('ACCOUNTING.cost_centers'); // one-level hierarchy in v1
            $table->boolean('is_active')->default(true);

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.cost_centers');
        Schema::dropIfExists('ACCOUNTING.fiscal_periods');
        Schema::dropIfExists('ACCOUNTING.fiscal_years');
        Schema::table('ACCOUNTING.accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_account_id']);
        });
        Schema::dropIfExists('ACCOUNTING.accounts');
        Schema::dropIfExists('ACCOUNTING.currencies');
        Schema::dropIfExists('ACCOUNTING.companies');
    }
};
