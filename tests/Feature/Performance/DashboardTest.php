<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrObjective;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3A Main Dashboard — rollup over every other Performance engine: KPI/OKR/Budget/Scorecard rows, metrics, and the breach-first "needs attention" list. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_dashboard_redirect_and_renders_with_defaults(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->get('/performance')->assertRedirect('/performance/dashboard');
        $this->get('/performance/dashboard')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Dashboard'));
    }

    public function test_dashboard_aggregates_kpi_okr_budget_and_scorecard_rows(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$periodId, $cycleId] = [null, null];
        $tenant->run(function () use (&$periodId, &$cycleId) {
            $period = $this->makePeriod();
            $periodId = $period->id;
            $cycle = $this->makeOkrCycle();
            $cycleId = $cycle->id;

            // A breaching KPI.
            $kpi = $this->makeKpiDefinition('Revenue', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->makeKpiValue($kpi, $period, ['actual_value' => 50]);

            // An off-track OKR.
            $this->makeOkrObjective($cycle, ['objective_text' => 'At risk objective', 'status' => OkrObjective::STATUS_OFF_TRACK]);

            // A breaching budget line.
            $budget = $this->makeBudget();
            $line = $this->makeBudgetLine($budget, $period, ['amount_planned' => 1000]);
            $this->makeBudgetActual($line, 1500);

            // A scored scorecard.
            $perspective = $this->makePerspective();
            $scorecard = $this->makeScorecard($period);
            $this->makeScorecardItem($scorecard, $perspective, ['kpi_id' => $kpi->id, 'weight' => 100]);
        });

        $this->get("/performance/dashboard?period_id={$periodId}&cycle_id={$cycleId}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('kpiRows', 1)
                ->has('okrRows', 1)
                ->has('budgetRows', 1)
                ->has('scorecardRows', 1)
                ->where('metrics.open_breaches', 3) // 1 breaching KPI + 1 breaching budget + 1 off-track OKR
                ->has('needsAttention', 3)
                ->where('needsAttention.0.rail', 'danger')); // breaches sort first
    }

    public function test_dashboard_metrics_are_null_or_zero_with_no_data(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->get('/performance/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.overall_scorecard_pct', null)
                ->where('metrics.budget_variance_pct', null)
                ->where('metrics.okrs_on_track', 0)
                ->where('metrics.okrs_total', 0)
                ->where('metrics.open_breaches', 0)
                ->has('needsAttention', 0));
    }

    public function test_dashboard_filters_by_subject_and_perspective(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$periodId, $perspectiveId] = [null, null];
        $tenant->run(function () use (&$periodId, &$perspectiveId) {
            $period = $this->makePeriod();
            $periodId = $period->id;
            $perspective = $this->makePerspective();
            $perspectiveId = $perspective->id;
            $otherPerspective = $this->makePerspective('Other');

            $kpi = $this->makeKpiDefinition('In Perspective', ['perspective_id' => $perspective->id]);
            $this->makeTarget($kpi, $period, ['target_value' => 100]);

            $otherKpi = $this->makeKpiDefinition('Other Perspective KPI', ['perspective_id' => $otherPerspective->id]);
            $this->makeTarget($otherKpi, $period, ['target_value' => 100]);
        });

        $this->get("/performance/dashboard?period_id={$periodId}&perspective_id={$perspectiveId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('kpiRows', 1));

        $this->get("/performance/dashboard?period_id={$periodId}&subject_type=org_unit&subject_id=999999")->assertOk()
            ->assertInertia(fn ($page) => $page->has('kpiRows', 0));
    }

    public function test_dashboard_recent_achievements_feed(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $badge = $this->makeBadgeDefinition();
            $this->makeAchievement($badge);
        });

        $this->get('/performance/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->has('recentAchievements', 1));
    }
}
