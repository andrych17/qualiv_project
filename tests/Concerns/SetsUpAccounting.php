<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\FakturPajakNumberBlock;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Models\WithholdingType;
use App\Modules\CRM\Models\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Shared bootstrap for Accounting module tests — plan activation, admin login, and fixtures. */
trait SetsUpAccounting
{
    protected function loginAsAccountingAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        // ACCOUNTING.gl_journals/exchange_rates FK to ACCOUNTING.currencies, but nothing in
        // SetsUpTenant::provisionTenant() seeds it (AccountingSeeder only runs via the full
        // DatabaseSeeder) — every fixture that might post a journal needs at least IDR/USD.
        // Must run inside $tenant->run() — provisionTenant() already ended tenancy by the time
        // it returns, so an unwrapped call here would hit the central DB instead.
        $tenant->run(function () {
            Currency::query()->firstOrCreate(['code' => 'IDR'], ['name' => 'Indonesian Rupiah', 'is_enabled' => true]);
            Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_enabled' => true]);
        });

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makeCompany(array $attrs = []): Company
    {
        return Company::query()->create([
            'legal_name' => $attrs['legal_name'] ?? 'Acme Corp',
            'npwp' => $attrs['npwp'] ?? null,
            'base_currency' => $attrs['base_currency'] ?? 'IDR',
            'fiscal_year_start_month' => $attrs['fiscal_year_start_month'] ?? 1,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeAccount(Company $company, array $attrs = []): Account
    {
        static $seq = 0;
        $seq++;

        return Account::query()->create([
            'company_id' => $company->id,
            'account_code' => $attrs['account_code'] ?? "9{$seq}00",
            'account_name' => $attrs['account_name'] ?? "Test Account {$seq}",
            'account_type' => $attrs['account_type'] ?? Account::TYPE_EXPENSE,
            'normal_balance' => $attrs['normal_balance'] ?? Account::BALANCE_DEBIT,
            'parent_account_id' => $attrs['parent_account_id'] ?? null,
            'is_control_account' => $attrs['is_control_account'] ?? false,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeCostCenter(Company $company, array $attrs = []): CostCenter
    {
        static $seq = 0;
        $seq++;

        return CostCenter::query()->create([
            'company_id' => $company->id,
            'code' => $attrs['code'] ?? "CC-{$seq}",
            'name' => $attrs['name'] ?? "Cost Center {$seq}",
            'parent_cost_center_id' => $attrs['parent_cost_center_id'] ?? null,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    /** Creates a fiscal year with its 12 monthly periods, mirroring FiscalYearService::create(). */
    protected function makeFiscalYear(Company $company, array $attrs = []): FiscalYear
    {
        $year = $attrs['year'] ?? 2026;
        $start = Carbon::parse($attrs['start_date'] ?? "{$year}-01-01");
        $end = $start->copy()->addYear()->subDay();

        $fiscalYear = FiscalYear::query()->create([
            'company_id' => $company->id,
            'year' => $year,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $attrs['status'] ?? FiscalYear::STATUS_OPEN,
        ]);

        $periodStart = $start->copy();
        for ($periodNo = 1; $periodNo <= 12; $periodNo++) {
            $periodEnd = $periodStart->copy()->addMonth()->subDay();
            FiscalPeriod::query()->create([
                'company_id' => $company->id,
                'fiscal_year_id' => $fiscalYear->id,
                'period_no' => $periodNo,
                'start_date' => $periodStart->copy(),
                'end_date' => $periodEnd,
                'status' => FiscalPeriod::STATUS_OPEN,
            ]);
            $periodStart = $periodStart->addMonth();
        }

        return $fiscalYear->refresh();
    }

    /** AR/AP/Cash-Bank *Service::post() methods take a non-nullable int $userId (unlike JournalService::post()). */
    protected function adminUserId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    protected function firstPeriod(FiscalYear $fiscalYear): FiscalPeriod
    {
        return FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->orderBy('period_no')->firstOrFail();
    }

    /** A draft journal with two balanced lines (first account debited, second credited). */
    protected function makeJournal(Company $company, FiscalPeriod $period, array $attrs = []): GlJournal
    {
        $journal = GlJournal::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'fiscal_period_id' => $period->id,
            'journal_date' => $attrs['journal_date'] ?? $period->start_date,
            'currency_code' => $attrs['currency_code'] ?? $company->base_currency,
            'memo' => $attrs['memo'] ?? 'Test journal',
            'source' => $attrs['source'] ?? GlJournal::SOURCE_MANUAL,
            'status' => $attrs['status'] ?? GlJournal::STATUS_DRAFT,
            'subject_type' => $attrs['subject_type'] ?? null,
            'subject_id' => $attrs['subject_id'] ?? null,
        ]);

        if (! ($attrs['skip_lines'] ?? false)) {
            $debitAccount = $attrs['debit_account'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $creditAccount = $attrs['credit_account'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $amount = $attrs['amount'] ?? 100000;

            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => 0]);
            GlJournalLine::query()->create(['journal_id' => $journal->id, 'line_no' => 2, 'account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $amount]);
        }

        return $journal->refresh();
    }

    protected function makeExchangeRate(Company $company, array $attrs = []): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'company_id' => $company->id,
            'currency_code' => $attrs['currency_code'] ?? 'USD',
            'rate_to_base' => $attrs['rate_to_base'] ?? 15500,
            'effective_date' => $attrs['effective_date'] ?? now()->toDateString(),
            'source' => ExchangeRate::SOURCE_MANUAL,
        ]);
    }

    /** CRM.partners FK target for ar_invoices/ar_payments/ap_bills/ap_payments — not seeded by provisionTenant(). */
    protected function makePartner(array $attrs = []): Partner
    {
        static $seq = 0;
        $seq++;

        return Partner::query()->create([
            'type' => $attrs['type'] ?? Partner::TYPE_ORGANIZATION,
            'name' => $attrs['name'] ?? "Test Partner {$seq}",
            'registration_tax_id' => $attrs['registration_tax_id'] ?? null,
        ]);
    }

    protected function makeTaxCode(Company $company, array $attrs = []): TaxCode
    {
        static $seq = 0;
        $seq++;

        return TaxCode::query()->create([
            'company_id' => $company->id,
            'code' => $attrs['code'] ?? "PPN{$seq}",
            'rate' => $attrs['rate'] ?? 11,
            'tax_type' => $attrs['tax_type'] ?? TaxCode::TYPE_OUTPUT,
            'gl_account_id' => $attrs['gl_account_id'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT])->id,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    /** Required before ArInvoiceService::post() can issue an output Faktur Pajak for a taxable line. */
    protected function makeFakturPajakBlock(Company $company, array $attrs = []): FakturPajakNumberBlock
    {
        return FakturPajakNumberBlock::query()->create([
            'company_id' => $company->id,
            'prefix' => $attrs['prefix'] ?? '010.000-26.',
            'range_start' => $attrs['range_start'] ?? 1,
            'range_end' => $attrs['range_end'] ?? 99999999,
            'last_issued' => $attrs['last_issued'] ?? null,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeWithholdingType(Company $company, array $attrs = []): WithholdingType
    {
        static $seq = 0;
        $seq++;

        return WithholdingType::query()->create([
            'company_id' => $company->id,
            'code' => $attrs['code'] ?? "PPH{$seq}",
            'bp_type' => $attrs['bp_type'] ?? '23',
            'name' => $attrs['name'] ?? 'PPh 23',
            'rate' => $attrs['rate'] ?? 2,
            'is_final' => $attrs['is_final'] ?? false,
            'gl_payable_account_id' => $attrs['gl_payable_account_id'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT])->id,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    /** §3G — a non-building Kelompok-1-style asset group (declining rate allowed). */
    protected function makeAssetGroup(Company $company, array $attrs = []): AssetGroup
    {
        static $seq = 0;
        $seq++;

        return AssetGroup::query()->create([
            'company_id' => $company->id,
            'code' => $attrs['code'] ?? "GROUP{$seq}",
            'name' => $attrs['name'] ?? "Group {$seq}",
            'is_building' => $attrs['is_building'] ?? false,
            'fiscal_useful_life_months' => $attrs['fiscal_useful_life_months'] ?? 48,
            'fiscal_straight_line_rate' => $attrs['fiscal_straight_line_rate'] ?? 0.25,
            'fiscal_declining_rate' => array_key_exists('fiscal_declining_rate', $attrs) ? $attrs['fiscal_declining_rate'] : 0.5,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    /** §3G — a straight-line/straight-line asset by default; 48-month commercial life, 12,000,000 cost (so straight-line depreciation is a clean 250,000/month). */
    protected function makeFixedAsset(Company $company, array $attrs = []): FixedAsset
    {
        static $seq = 0;
        $seq++;

        $assetGroup = $attrs['asset_group_id'] ?? $this->makeAssetGroup($company)->id;

        return FixedAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'asset_group_id' => $assetGroup,
            'asset_no' => $attrs['asset_no'] ?? "FA-{$seq}",
            'name' => $attrs['name'] ?? "Asset {$seq}",
            'vendor_partner_id' => $attrs['vendor_partner_id'] ?? null,
            'acquisition_date' => $attrs['acquisition_date'] ?? '2026-01-01',
            'acquisition_cost' => $attrs['acquisition_cost'] ?? 12000000,
            'asset_gl_account_id' => $attrs['asset_gl_account_id'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
            'accumulated_depreciation_gl_account_id' => $attrs['accumulated_depreciation_gl_account_id'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
            'depreciation_expense_gl_account_id' => $attrs['depreciation_expense_gl_account_id'] ?? $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id,
            'commercial_useful_life_months' => $attrs['commercial_useful_life_months'] ?? 48,
            'commercial_method' => $attrs['commercial_method'] ?? FixedAsset::METHOD_STRAIGHT_LINE,
            'commercial_declining_rate' => $attrs['commercial_declining_rate'] ?? null,
            'fiscal_method' => $attrs['fiscal_method'] ?? FixedAsset::METHOD_STRAIGHT_LINE,
            'subject_type' => $attrs['subject_type'] ?? null,
            'subject_id' => $attrs['subject_id'] ?? null,
            'status' => $attrs['status'] ?? FixedAsset::STATUS_ACTIVE,
        ]);
    }
}
