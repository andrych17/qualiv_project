<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Data\VarianceResult;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Services\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3G Variance Analysis Engine — exhaustive direct coverage of the one shared plan-vs-actual
 * primitive every other Performance engine calls: KPI (directional), Budget/Forecast (symmetric).
 */
class VarianceServiceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    // --- evaluateKpi() null-guard branches ---

    public function test_evaluate_kpi_returns_null_when_kpi_target_or_value_is_missing(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);

            $this->assertNull($variance->evaluateKpi('company', null, 999999, 1)); // no such KPI

            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $this->assertNull($variance->evaluateKpi('company', null, $kpi->id, $period->id)); // no target, no value

            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->assertNull($variance->evaluateKpi('company', null, $kpi->id, $period->id)); // target but no value
        });
    }

    // --- classifyKpi() directional thresholds, both directions, zero-plan edge case ---

    public function test_classify_kpi_higher_is_better_bands(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $period = $this->makePeriod();

            $onTrack = $this->makeKpiDefinition('HIB On Track', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($onTrack, $period, ['target_value' => 100]);
            $this->makeKpiValue($onTrack, $period, ['actual_value' => 100]); // exact match
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateKpi('company', null, $onTrack->id, $period->id)->status);

            $overachieve = $this->makeKpiDefinition('HIB Overachieve', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($overachieve, $period, ['target_value' => 100]);
            $this->makeKpiValue($overachieve, $period, ['actual_value' => 150]); // beating target is always on_track
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateKpi('company', null, $overachieve->id, $period->id)->status);

            $warning = $this->makeKpiDefinition('HIB Warning', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($warning, $period, ['target_value' => 100]);
            $this->makeKpiValue($warning, $period, ['actual_value' => 90]); // 10% shortfall
            $this->assertSame(VarianceResult::STATUS_WARNING, $variance->evaluateKpi('company', null, $warning->id, $period->id)->status);

            $breach = $this->makeKpiDefinition('HIB Breach', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($breach, $period, ['target_value' => 100]);
            $this->makeKpiValue($breach, $period, ['actual_value' => 70]); // 30% shortfall
            $result = $variance->evaluateKpi('company', null, $breach->id, $period->id);
            $this->assertSame(VarianceResult::STATUS_BREACH, $result->status);
            $this->assertEqualsWithDelta(-30.0, $result->variancePct, 0.001);
        });
    }

    public function test_classify_kpi_lower_is_better_bands(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $period = $this->makePeriod();

            $underachieve = $this->makeKpiDefinition('LIB Underachieve', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($underachieve, $period, ['target_value' => 10]);
            $this->makeKpiValue($underachieve, $period, ['actual_value' => 5]); // beating a lower-is-better target
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateKpi('company', null, $underachieve->id, $period->id)->status);

            $breach = $this->makeKpiDefinition('LIB Breach', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($breach, $period, ['target_value' => 10]);
            $this->makeKpiValue($breach, $period, ['actual_value' => 15]); // 50% over a lower-is-better target
            $this->assertSame(VarianceResult::STATUS_BREACH, $variance->evaluateKpi('company', null, $breach->id, $period->id)->status);
        });
    }

    public function test_classify_kpi_zero_target_falls_back_to_sign_check(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $period = $this->makePeriod();

            $hibFavorable = $this->makeKpiDefinition('HIB Zero Favorable', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($hibFavorable, $period, ['target_value' => 0]);
            $this->makeKpiValue($hibFavorable, $period, ['actual_value' => 5]);
            $result = $variance->evaluateKpi('company', null, $hibFavorable->id, $period->id);
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $result->status);
            $this->assertNull($result->variancePct);

            $hibUnfavorable = $this->makeKpiDefinition('HIB Zero Unfavorable', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($hibUnfavorable, $period, ['target_value' => 0]);
            $this->makeKpiValue($hibUnfavorable, $period, ['actual_value' => -5]);
            $this->assertSame(VarianceResult::STATUS_BREACH, $variance->evaluateKpi('company', null, $hibUnfavorable->id, $period->id)->status);

            $libFavorable = $this->makeKpiDefinition('LIB Zero Favorable', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($libFavorable, $period, ['target_value' => 0]);
            $this->makeKpiValue($libFavorable, $period, ['actual_value' => -5]);
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateKpi('company', null, $libFavorable->id, $period->id)->status);

            $libUnfavorable = $this->makeKpiDefinition('LIB Zero Unfavorable', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($libUnfavorable, $period, ['target_value' => 0]);
            $this->makeKpiValue($libUnfavorable, $period, ['actual_value' => 5]);
            $this->assertSame(VarianceResult::STATUS_BREACH, $variance->evaluateKpi('company', null, $libUnfavorable->id, $period->id)->status);
        });
    }

    // --- evaluateBudgetLine() / resolveGlActual() branches ---

    public function test_evaluate_budget_line_returns_null_with_no_actual_source(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $this->makePeriod());

            $this->assertNull($variance->evaluateBudgetLine($line));
        });
    }

    public function test_resolve_gl_actual_skips_when_accounting_feature_disabled(): void
    {
        // A 'starter' plan tenant has no ACCOUNTING feature — even a fully-mapped category
        // must fall back to manual (or null) rather than reading GL data.
        $tenant = $this->provisionTenant('varperfnoacct');
        $tenant->update(['plan' => 'starter']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $company = $this->makeCompany();
            $account = $this->makeAccount($company);
            $this->makeBudgetCategoryAccount('Marketing', $account);

            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $this->makePeriod(), ['category' => 'Marketing']);
            $this->makeBudgetActual($line, 42);

            $result = $variance->evaluateBudgetLine($line);
            $this->assertNotNull($result);
            $this->assertSame(VarianceResult::SOURCE_MANUAL, $result->actualSource);
        });
    }

    public function test_resolve_gl_actual_skips_unresolvable_company_account_and_period_rows(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);

            // Two active companies -> resolveDefaultCompanyId() can't pick one for a
            // company-agnostic mapping, so that mapping row is skipped entirely.
            $companyA = $this->makeCompany(['legal_name' => 'Company A']);
            $companyB = $this->makeCompany(['legal_name' => 'Company B']);
            $account = $this->makeAccount($companyA);
            $this->makeBudgetCategoryAccount('Ambiguous Company', $account); // no company_id set

            $period = $this->makePeriod('Ambiguous Period');
            $fiscalPeriod = $this->makeFiscalPeriod($companyA, $period);
            $this->makePostedJournalLine($account, $fiscalPeriod, 1000);

            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $period, ['category' => 'Ambiguous Company']);
            $this->makeBudgetActual($line, 55); // manual fallback should win since GL resolution skips

            $result = $variance->evaluateBudgetLine($line);
            $this->assertSame(VarianceResult::SOURCE_MANUAL, $result->actualSource);
            $this->assertEqualsWithDelta(55.0, $result->actualValue, 0.001);
        });
    }

    public function test_resolve_gl_actual_skips_when_no_matching_fiscal_period_exists(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $company = $this->makeCompany();
            $account = $this->makeAccount($company);
            $this->makeBudgetCategoryAccount('No Fiscal Period', $account, ['company_id' => $company->id]);

            // No FiscalPeriod created at all for this Performance period's date range.
            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $this->makePeriod(), ['category' => 'No Fiscal Period']);

            $this->assertNull($variance->evaluateBudgetLine($line)); // no manual fallback either
        });
    }

    public function test_resolve_gl_actual_sums_multiple_mapped_accounts(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $company = $this->makeCompany();
            $accountA = $this->makeAccount($company, ['account_code' => '6100', 'account_name' => 'Ads']);
            $accountB = $this->makeAccount($company, ['account_code' => '6200', 'account_name' => 'Events']);
            $this->makeBudgetCategoryAccount('Combined Marketing', $accountA, ['company_id' => $company->id]);
            $this->makeBudgetCategoryAccount('Combined Marketing', $accountB, ['company_id' => $company->id]);

            $period = $this->makePeriod('Combined Period');
            $fiscalPeriod = $this->makeFiscalPeriod($company, $period);
            $this->makePostedJournalLine($accountA, $fiscalPeriod, 300);
            $this->makePostedJournalLine($accountB, $fiscalPeriod, 200);

            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $period, ['category' => 'Combined Marketing', 'amount_planned' => 500]);

            $result = $variance->evaluateBudgetLine($line);
            $this->assertSame(VarianceResult::SOURCE_GL, $result->actualSource);
            $this->assertEqualsWithDelta(500.0, $result->actualValue, 0.001);
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $result->status);
        });
    }

    public function test_resolve_gl_actual_ignores_an_inactive_mapping(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $company = $this->makeCompany();
            $account = $this->makeAccount($company);
            $this->makeBudgetCategoryAccount('Inactive Mapping', $account, ['company_id' => $company->id, 'is_active' => false]);

            $period = $this->makePeriod();
            $fiscalPeriod = $this->makeFiscalPeriod($company, $period);
            $this->makePostedJournalLine($account, $fiscalPeriod, 999);

            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $period, ['category' => 'Inactive Mapping']);
            $this->makeBudgetActual($line, 10);

            $result = $variance->evaluateBudgetLine($line);
            $this->assertSame(VarianceResult::SOURCE_MANUAL, $result->actualSource);
        });
    }

    // --- classifyBudgetVariance() symmetric thresholds + zero-plan edge case ---

    public function test_classify_budget_variance_symmetric_bands(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $period = $this->makePeriod();

            $budget = $this->makeBudget();

            $onTrackLine = $this->makeBudgetLine($budget, $period, ['category' => 'On Track', 'amount_planned' => 1000]);
            $this->makeBudgetActual($onTrackLine, 1000);
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateBudgetLine($onTrackLine)->status);

            $underspendWarning = $this->makeBudgetLine($budget, $period, ['category' => 'Underspend Warning', 'amount_planned' => 1000]);
            $this->makeBudgetActual($underspendWarning, 900); // 10% under is still a "warning" signal, symmetric
            $this->assertSame(VarianceResult::STATUS_WARNING, $variance->evaluateBudgetLine($underspendWarning)->status);

            $overspendBreach = $this->makeBudgetLine($budget, $period, ['category' => 'Overspend Breach', 'amount_planned' => 1000]);
            $this->makeBudgetActual($overspendBreach, 1300); // 30% over
            $this->assertSame(VarianceResult::STATUS_BREACH, $variance->evaluateBudgetLine($overspendBreach)->status);
        });
    }

    public function test_classify_budget_variance_zero_plan_edge_case(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);
            $period = $this->makePeriod();
            $budget = $this->makeBudget();

            $zeroZero = $this->makeBudgetLine($budget, $period, ['category' => 'Zero Zero', 'amount_planned' => 0]);
            $this->makeBudgetActual($zeroZero, 0);
            $this->assertSame(VarianceResult::STATUS_ON_TRACK, $variance->evaluateBudgetLine($zeroZero)->status);

            $zeroNonzero = $this->makeBudgetLine($budget, $period, ['category' => 'Zero Nonzero', 'amount_planned' => 0]);
            $this->makeBudgetActual($zeroNonzero, 50);
            $result = $variance->evaluateBudgetLine($zeroNonzero);
            $this->assertSame(VarianceResult::STATUS_BREACH, $result->status);
            $this->assertNull($result->variancePct);
        });
    }
}
