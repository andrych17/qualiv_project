<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\Forecast;
use App\Modules\Performance\Services\VarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3H Forecast — immutable once created, budget-xor-kpi link, non-destructive revision versioning. */
class ForecastTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_create_a_kpi_linked_forecast(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpiId = $this->makeKpiDefinition()->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->get('/performance/forecasts')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Forecasts/Index'));

        $this->post('/performance/forecasts', [
            'kpi_id' => $kpiId,
            'subject_type' => Forecast::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'lines' => [['period_id' => $periodId, 'forecast_value' => 500]],
        ])->assertRedirect(route('performance.forecasts.index'));

        $tenant->run(function () use ($kpiId) {
            $forecast = Forecast::query()->where('kpi_id', $kpiId)->first();
            $this->assertNotNull($forecast);
            $this->assertSame(1, $forecast->version_no);
            $this->assertTrue($forecast->is_latest);
            $this->assertNull($forecast->root_forecast_id);
            $this->assertSame(1, $forecast->lines()->count());
        });
    }

    public function test_admin_can_create_a_budget_linked_forecast_inheriting_its_subject(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$budgetId, $periodId] = [null, null];
        $tenant->run(function () use (&$budgetId, &$periodId) {
            $budget = $this->makeBudget(['subject_type' => Budget::SUBJECT_COMPANY]);
            $budgetId = $budget->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/forecasts', [
            'budget_id' => $budgetId,
            'period_id' => $periodId,
        ])->assertRedirect();

        $tenant->run(function () use ($budgetId) {
            $forecast = Forecast::query()->where('budget_id', $budgetId)->first();
            $this->assertNotNull($forecast);
            $this->assertNull($forecast->kpi_id);
        });
    }

    public function test_store_rejects_neither_or_both_budget_and_kpi(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/forecasts', ['period_id' => $periodId])->assertSessionHasErrors(['budget_id']);

        [$budgetId, $kpiId] = [null, null];
        $tenant->run(function () use (&$budgetId, &$kpiId) {
            $budgetId = $this->makeBudget()->id;
            $kpiId = $this->makeKpiDefinition()->id;
        });

        $this->post('/performance/forecasts', ['budget_id' => $budgetId, 'kpi_id' => $kpiId, 'period_id' => $periodId])
            ->assertSessionHasErrors(['budget_id']);
    }

    public function test_store_rejects_invalid_budget_kpi_and_period(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/forecasts', ['budget_id' => 999999, 'period_id' => 999999])
            ->assertSessionHasErrors(['budget_id', 'period_id']);
    }

    public function test_admin_can_revise_a_forecast_creating_a_new_version(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$forecastId, $newPeriodId] = [null, null];
        $tenant->run(function () use (&$forecastId, &$newPeriodId) {
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $forecast = $this->makeForecast($period, ['kpi_id' => $kpi->id]);
            $this->makeForecastLine($forecast, $period, 500);
            $forecastId = $forecast->id;
            $newPeriodId = $this->makePeriod('2027', ['year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'])->id;
        });

        $this->get("/performance/forecasts/{$forecastId}/revise")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Forecasts/Revise'));

        $this->post("/performance/forecasts/{$forecastId}/revise", [
            'period_id' => $newPeriodId,
            'lines' => [['period_id' => $newPeriodId, 'forecast_value' => 750]],
        ])->assertRedirect();

        $tenant->run(function () use ($forecastId) {
            $original = Forecast::query()->find($forecastId);
            $this->assertFalse($original->is_latest);

            $newVersion = Forecast::query()->where('root_forecast_id', $forecastId)->first();
            $this->assertNotNull($newVersion);
            $this->assertSame(2, $newVersion->version_no);
            $this->assertTrue($newVersion->is_latest);
        });
    }

    public function test_only_the_latest_version_can_be_revised(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$originalId, $periodId] = [null, null];
        $tenant->run(function () use (&$originalId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $periodId = $period->id;
            $originalId = $this->makeForecast($period, ['kpi_id' => $kpi->id, 'is_latest' => false])->id;
        });

        $this->post("/performance/forecasts/{$originalId}/revise", ['period_id' => $periodId])->assertSessionHasErrors();
    }

    public function test_forecast_delete_allowed_only_for_an_unrevised_v1(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $forecastId = null;
        $tenant->run(function () use (&$forecastId) {
            $kpi = $this->makeKpiDefinition();
            $forecastId = $this->makeForecast($this->makePeriod(), ['kpi_id' => $kpi->id])->id;
        });

        $this->delete("/performance/forecasts/{$forecastId}")->assertRedirect(route('performance.forecasts.index'));
        $tenant->run(function () use ($forecastId) {
            $this->assertNull(Forecast::query()->find($forecastId));
        });
    }

    public function test_forecast_delete_rejects_a_revised_series_member(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $originalId = null;
        $tenant->run(function () use (&$originalId) {
            $kpi = $this->makeKpiDefinition();
            $original = $this->makeForecast($this->makePeriod(), ['kpi_id' => $kpi->id, 'is_latest' => false]);
            $originalId = $original->id;
            $this->makeForecast($this->makePeriod('v2'), ['kpi_id' => $kpi->id, 'version_no' => 2, 'root_forecast_id' => $original->id]);
        });

        $this->delete("/performance/forecasts/{$originalId}")->assertSessionHasErrors();
    }

    public function test_forecast_index_filters_by_series_and_defaults_to_latest_only(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$rootId] = [null];
        $tenant->run(function () use (&$rootId) {
            $kpi = $this->makeKpiDefinition();
            $root = $this->makeForecast($this->makePeriod(), ['kpi_id' => $kpi->id, 'is_latest' => false]);
            $rootId = $root->id;
            $this->makeForecast($this->makePeriod('v2 period'), ['kpi_id' => $kpi->id, 'version_no' => 2, 'root_forecast_id' => $root->id]);
        });

        // Default view shows only latest versions across all series (1 row).
        $this->get('/performance/forecasts')->assertOk()->assertInertia(fn ($page) => $page->has('forecasts.data', 1));

        // Explicit series filter shows every version in that series (2 rows).
        $this->get("/performance/forecasts?series={$rootId}")->assertOk()->assertInertia(fn ($page) => $page->has('forecasts.data', 2));
    }

    public function test_evaluate_forecast_line_compares_against_kpi_actual_or_budget_planned_total(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $variance = app(VarianceService::class);

            // KPI-linked: compares against the real recorded actual for the same period.
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $this->makeKpiValue($kpi, $period, ['actual_value' => 120]);
            $kpiForecast = $this->makeForecast($period, ['kpi_id' => $kpi->id]);
            $kpiLine = $this->makeForecastLine($kpiForecast, $period, 100);

            $result = $variance->evaluateForecastLine($kpiLine);
            $this->assertNotNull($result);
            $this->assertEqualsWithDelta(120.0, $result->actualValue, 0.001);

            // No KpiValue yet for a different KPI -> null.
            $kpi2 = $this->makeKpiDefinition('Unmeasured KPI');
            $kpiForecast2 = $this->makeForecast($period, ['kpi_id' => $kpi2->id]);
            $kpiLine2 = $this->makeForecastLine($kpiForecast2, $period, 100);
            $this->assertNull($variance->evaluateForecastLine($kpiLine2));

            // Budget-linked: compares against the linked budget's total planned amount for that period.
            $budget = $this->makeBudget();
            $this->makeBudgetLine($budget, $period, ['category' => 'A', 'amount_planned' => 300]);
            $this->makeBudgetLine($budget, $period, ['category' => 'B', 'amount_planned' => 200]);
            $budgetForecast = $this->makeForecast($period, ['budget_id' => $budget->id]);
            $budgetLine = $this->makeForecastLine($budgetForecast, $period, 450);

            $budgetResult = $variance->evaluateForecastLine($budgetLine);
            $this->assertNotNull($budgetResult);
            $this->assertEqualsWithDelta(500.0, $budgetResult->actualValue, 0.001);

            // A budget with no lines at all for that period -> null.
            $emptyBudget = $this->makeBudget(['name' => 'Empty Budget']);
            $emptyBudgetForecast = $this->makeForecast($period, ['budget_id' => $emptyBudget->id]);
            $emptyBudgetLine = $this->makeForecastLine($emptyBudgetForecast, $period, 100);
            $this->assertNull($variance->evaluateForecastLine($emptyBudgetLine));
        });
    }
}
