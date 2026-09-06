<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\BudgetActual;
use App\Modules\Performance\Models\BudgetCategoryAccount;
use App\Modules\Performance\Models\BudgetLine;
use App\Modules\Performance\Models\Forecast;
use App\Modules\Performance\Models\ForecastLine;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\KpiValue;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrKeyResult;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Models\ScorecardItem;
use App\Modules\Performance\Models\Target;
use Illuminate\Support\Str;

/** Shared bootstrap for Performance module tests — plan activation, admin login, and fixtures. */
trait SetsUpPerformance
{
    protected function loginAsPerformanceAdmin(): Tenant
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        return $tenant;
    }

    protected function makePerspective(string $name = 'Financial', array $attrs = []): Perspective
    {
        return Perspective::query()->firstOrCreate(['name' => $name], ['is_active' => true, ...$attrs]);
    }

    protected function makePeriod(string $label = '2026', array $attrs = []): Period
    {
        return Period::query()->firstOrCreate(['label' => $label], [
            'period_type' => $attrs['period_type'] ?? Period::TYPE_YEAR,
            'year' => $attrs['year'] ?? 2026,
            'quarter' => $attrs['quarter'] ?? null,
            'month' => $attrs['month'] ?? null,
            'start_date' => $attrs['start_date'] ?? '2026-01-01',
            'end_date' => $attrs['end_date'] ?? '2026-12-31',
            'is_active' => true,
        ]);
    }

    protected function makeKpiDefinition(string $name = 'Revenue', array $attrs = []): KpiDefinition
    {
        return KpiDefinition::query()->create([
            'name' => $name,
            'unit' => $attrs['unit'] ?? KpiDefinition::UNIT_NUMBER,
            'direction' => $attrs['direction'] ?? KpiDefinition::DIRECTION_HIGHER_IS_BETTER,
            'perspective_id' => $attrs['perspective_id'] ?? null,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeTarget(KpiDefinition $kpi, Period $period, array $attrs = []): Target
    {
        return Target::query()->create([
            'kpi_id' => $kpi->id,
            'subject_type' => $attrs['subject_type'] ?? Target::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'period_id' => $period->id,
            'target_value' => $attrs['target_value'] ?? 100,
            'stretch_value' => $attrs['stretch_value'] ?? null,
        ]);
    }

    protected function makeKpiValue(KpiDefinition $kpi, Period $period, array $attrs = []): KpiValue
    {
        return KpiValue::query()->create([
            'kpi_id' => $kpi->id,
            'subject_type' => $attrs['subject_type'] ?? KpiValue::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'period_id' => $period->id,
            'actual_value' => $attrs['actual_value'] ?? 100,
            'source' => KpiValue::SOURCE_MANUAL,
            'entered_at' => now(),
        ]);
    }

    protected function makeOkrCycle(string $label = '2026 Q1', array $attrs = []): OkrCycle
    {
        return OkrCycle::query()->firstOrCreate(['label' => $label], [
            'start_date' => $attrs['start_date'] ?? '2026-01-01',
            'end_date' => $attrs['end_date'] ?? '2026-03-31',
            'is_active' => true,
        ]);
    }

    protected function makeOkrObjective(OkrCycle $cycle, array $attrs = []): OkrObjective
    {
        return OkrObjective::query()->create([
            'cycle_id' => $cycle->id,
            'subject_type' => $attrs['subject_type'] ?? OkrObjective::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'objective_text' => $attrs['objective_text'] ?? 'Grow revenue',
            'parent_okr_id' => $attrs['parent_okr_id'] ?? null,
            'status' => $attrs['status'] ?? OkrObjective::STATUS_ON_TRACK,
        ]);
    }

    protected function makeKeyResult(OkrObjective $objective, array $attrs = []): OkrKeyResult
    {
        return OkrKeyResult::query()->create([
            'okr_id' => $objective->id,
            'description' => $attrs['description'] ?? 'Ship feature X',
            'metric_type' => $attrs['metric_type'] ?? OkrKeyResult::METRIC_NUMERIC,
            'start_value' => $attrs['start_value'] ?? 0,
            'current_value' => $attrs['current_value'] ?? 50,
            'target_value' => $attrs['target_value'] ?? 100,
            'weight' => $attrs['weight'] ?? 100,
        ]);
    }

    protected function makeBudget(array $attrs = []): Budget
    {
        return Budget::query()->create([
            'name' => $attrs['name'] ?? 'FY Budget',
            'subject_type' => $attrs['subject_type'] ?? Budget::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'fiscal_year' => $attrs['fiscal_year'] ?? 2026,
            'fiscal_quarter' => $attrs['fiscal_quarter'] ?? null,
            'status' => $attrs['status'] ?? Budget::STATUS_DRAFT,
            'version_no' => $attrs['version_no'] ?? 1,
            'prior_version_id' => $attrs['prior_version_id'] ?? null,
        ]);
    }

    protected function makeBudgetLine(Budget $budget, Period $period, array $attrs = []): BudgetLine
    {
        return BudgetLine::query()->create([
            'budget_id' => $budget->id,
            'category' => $attrs['category'] ?? 'Marketing',
            'period_id' => $period->id,
            'amount_planned' => $attrs['amount_planned'] ?? 1000,
        ]);
    }

    protected function makeBudgetActual(BudgetLine $line, float $actualValue = 1000): BudgetActual
    {
        return BudgetActual::query()->create([
            'budget_line_id' => $line->id,
            'actual_value' => $actualValue,
            'source' => BudgetActual::SOURCE_MANUAL,
            'entered_at' => now(),
        ]);
    }

    protected function makeBudgetCategoryAccount(string $category, Account $account, array $attrs = []): BudgetCategoryAccount
    {
        return BudgetCategoryAccount::query()->create([
            'category' => $category,
            'account_id' => $account->id,
            'company_id' => $attrs['company_id'] ?? null,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeForecast(Period $period, array $attrs = []): Forecast
    {
        return Forecast::query()->create([
            'subject_type' => $attrs['subject_type'] ?? Forecast::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'budget_id' => $attrs['budget_id'] ?? null,
            'kpi_id' => $attrs['kpi_id'] ?? null,
            'period_id' => $period->id,
            'method' => Forecast::METHOD_MANUAL,
            'version_no' => $attrs['version_no'] ?? 1,
            'root_forecast_id' => $attrs['root_forecast_id'] ?? null,
            'is_latest' => $attrs['is_latest'] ?? true,
        ]);
    }

    protected function makeForecastLine(Forecast $forecast, Period $period, float $value = 1000): ForecastLine
    {
        return ForecastLine::query()->create([
            'forecast_id' => $forecast->id,
            'period_id' => $period->id,
            'forecast_value' => $value,
        ]);
    }

    protected function makeScorecard(Period $period, array $attrs = []): Scorecard
    {
        return Scorecard::query()->create([
            'name' => $attrs['name'] ?? 'Company Scorecard',
            'subject_type' => $attrs['subject_type'] ?? Scorecard::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'period_id' => $period->id,
        ]);
    }

    protected function makeScorecardItem(Scorecard $scorecard, Perspective $perspective, array $attrs = []): ScorecardItem
    {
        return ScorecardItem::query()->create([
            'scorecard_id' => $scorecard->id,
            'perspective_id' => $perspective->id,
            'kpi_id' => $attrs['kpi_id'] ?? null,
            'okr_id' => $attrs['okr_id'] ?? null,
            'weight' => $attrs['weight'] ?? 100,
        ]);
    }

    protected function makeBadgeDefinition(string $name = 'Target Hit', array $attrs = []): BadgeDefinition
    {
        return BadgeDefinition::query()->create([
            'name' => $name,
            'trigger_type' => $attrs['trigger_type'] ?? BadgeDefinition::TRIGGER_TARGET_HIT,
            'trigger_params' => $attrs['trigger_params'] ?? null,
            'is_active' => $attrs['is_active'] ?? true,
        ]);
    }

    protected function makeAchievement(BadgeDefinition $badge, array $attrs = []): Achievement
    {
        return Achievement::query()->create([
            'subject_type' => $attrs['subject_type'] ?? Achievement::SUBJECT_COMPANY,
            'subject_id' => $attrs['subject_id'] ?? null,
            'badge_id' => $badge->id,
            'kpi_id' => $attrs['kpi_id'] ?? null,
            'okr_id' => $attrs['okr_id'] ?? null,
            'period_id' => $attrs['period_id'] ?? null,
            'earned_at' => now(),
            'awarded_by' => $attrs['awarded_by'] ?? null,
        ]);
    }

    // --- Accounting fixtures (§3B GL-sourced budget actuals) ---

    protected function makeCompany(array $attrs = []): Company
    {
        // AccountingSeeder (which normally seeds IDR/USD) only runs via the full DatabaseSeeder,
        // not SetsUpTenant::provisionTenant() — gl_journals.currency_code FKs to this table, so
        // any test posting a journal needs it seeded itself.
        Currency::query()->firstOrCreate(['code' => 'IDR'], ['name' => 'Indonesian Rupiah', 'is_enabled' => true]);

        return Company::query()->create([
            'legal_name' => $attrs['legal_name'] ?? 'Acme Corp',
            'base_currency' => $attrs['base_currency'] ?? 'IDR',
            'fiscal_year_start_month' => $attrs['fiscal_year_start_month'] ?? 1,
            'is_active' => true,
        ]);
    }

    protected function makeAccount(Company $company, array $attrs = []): Account
    {
        return Account::query()->create([
            'company_id' => $company->id,
            'account_code' => $attrs['account_code'] ?? '6100',
            'account_name' => $attrs['account_name'] ?? 'Marketing Expense',
            'account_type' => $attrs['account_type'] ?? Account::TYPE_EXPENSE,
            'normal_balance' => $attrs['normal_balance'] ?? Account::BALANCE_DEBIT,
            'is_active' => true,
        ]);
    }

    protected function makeFiscalPeriod(Company $company, Period $period, array $attrs = []): FiscalPeriod
    {
        $fiscalYear = FiscalYear::query()->create([
            'company_id' => $company->id,
            'year' => $attrs['year'] ?? (int) $period->year,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => FiscalYear::STATUS_OPEN,
        ]);

        return FiscalPeriod::query()->create([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_no' => $attrs['period_no'] ?? 1,
            'start_date' => $period->start_date,
            'end_date' => $period->end_date,
            'status' => FiscalPeriod::STATUS_OPEN,
        ]);
    }

    /** A posted, balanced journal with one line against $account for $amount (debit if account is debit-normal, else credit). */
    protected function makePostedJournalLine(Account $account, FiscalPeriod $period, float $amount): GlJournalLine
    {
        $journal = GlJournal::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $account->company_id,
            'fiscal_period_id' => $period->id,
            'journal_date' => $period->start_date,
            'currency_code' => 'IDR',
            'memo' => 'Test posting',
            'source' => GlJournal::SOURCE_MANUAL,
            'status' => GlJournal::STATUS_POSTED,
        ]);

        $isDebitNormal = $account->normal_balance === Account::BALANCE_DEBIT;

        return GlJournalLine::query()->create([
            'journal_id' => $journal->id,
            'line_no' => 1,
            'account_id' => $account->id,
            'cost_center_id' => null,
            'debit' => $isDebitNormal ? $amount : 0,
            'credit' => $isDebitNormal ? 0 : $amount,
        ]);
    }
}
