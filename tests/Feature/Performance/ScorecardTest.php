<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrKeyResult;
use App\Modules\Performance\Models\Scorecard;
use App\Modules\Performance\Services\ScorecardScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F Scorecard Builder & Viewer — kpi-xor-okr items, per-perspective weight-sum-100 validation, live-computed scoring. */
class ScorecardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_create_a_scorecard_with_weighted_items(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$perspectiveId, $kpiId, $periodId] = [null, null, null];
        $tenant->run(function () use (&$perspectiveId, &$kpiId, &$periodId) {
            $perspectiveId = $this->makePerspective()->id;
            $kpiId = $this->makeKpiDefinition()->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/scorecards', [
            'name' => 'Company Scorecard',
            'subject_type' => Scorecard::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'items' => [['perspective_id' => $perspectiveId, 'kpi_id' => $kpiId, 'weight' => 100]],
        ])->assertRedirect();

        $tenant->run(function () {
            $scorecard = Scorecard::query()->where('name', 'Company Scorecard')->first();
            $this->assertNotNull($scorecard);
            $this->assertSame(1, $scorecard->items()->count());
        });
    }

    public function test_store_rejects_item_with_both_or_neither_kpi_and_okr(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$perspectiveId, $kpiId, $okrId, $periodId] = [null, null, null, null];
        $tenant->run(function () use (&$perspectiveId, &$kpiId, &$okrId, &$periodId) {
            $perspectiveId = $this->makePerspective()->id;
            $kpiId = $this->makeKpiDefinition()->id;
            $okrId = $this->makeOkrObjective($this->makeOkrCycle())->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/scorecards', [
            'name' => 'Neither', 'subject_type' => Scorecard::SUBJECT_COMPANY, 'period_id' => $periodId,
            'items' => [['perspective_id' => $perspectiveId, 'weight' => 100]],
        ])->assertSessionHasErrors(['items']);

        $this->post('/performance/scorecards', [
            'name' => 'Both', 'subject_type' => Scorecard::SUBJECT_COMPANY, 'period_id' => $periodId,
            'items' => [['perspective_id' => $perspectiveId, 'kpi_id' => $kpiId, 'okr_id' => $okrId, 'weight' => 100]],
        ])->assertSessionHasErrors(['items']);
    }

    public function test_store_rejects_perspective_weights_not_summing_to_100(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$perspectiveId, $kpi1, $kpi2, $periodId] = [null, null, null, null];
        $tenant->run(function () use (&$perspectiveId, &$kpi1, &$kpi2, &$periodId) {
            $perspectiveId = $this->makePerspective()->id;
            $kpi1 = $this->makeKpiDefinition('KPI 1')->id;
            $kpi2 = $this->makeKpiDefinition('KPI 2')->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/scorecards', [
            'name' => 'Bad Weights', 'subject_type' => Scorecard::SUBJECT_COMPANY, 'period_id' => $periodId,
            'items' => [
                ['perspective_id' => $perspectiveId, 'kpi_id' => $kpi1, 'weight' => 60],
                ['perspective_id' => $perspectiveId, 'kpi_id' => $kpi2, 'weight' => 30],
            ],
        ])->assertSessionHasErrors(['items']);
    }

    public function test_store_rejects_invalid_perspective_kpi_okr_period_and_subject(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/scorecards', [
            'name' => 'Bad Refs', 'subject_type' => Scorecard::SUBJECT_ORG_UNIT, 'subject_id' => 999999, 'period_id' => 999999,
            'items' => [['perspective_id' => 999999, 'kpi_id' => 999999, 'weight' => 100]],
        ])->assertSessionHasErrors(['period_id', 'subject_id', 'items.0.perspective_id', 'items.0.kpi_id']);
    }

    public function test_admin_can_update_and_delete_a_scorecard(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$scorecardId, $perspectiveId, $kpiId] = [null, null, null];
        $tenant->run(function () use (&$scorecardId, &$perspectiveId, &$kpiId) {
            $perspective = $this->makePerspective();
            $perspectiveId = $perspective->id;
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $scorecard = $this->makeScorecard($this->makePeriod());
            $this->makeScorecardItem($scorecard, $perspective, ['kpi_id' => $kpi->id, 'weight' => 100]);
            $scorecardId = $scorecard->id;
        });

        $this->get("/performance/scorecards/{$scorecardId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Scorecards/Show'));

        $this->get("/performance/scorecards/{$scorecardId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Scorecards/Edit')->has('scorecard.items', 1));

        $tenant->run(function () use ($scorecardId, $perspectiveId, $kpiId) {
            $scorecard = Scorecard::query()->find($scorecardId);
            $this->put("/performance/scorecards/{$scorecardId}", [
                'name' => 'Renamed Scorecard',
                'subject_type' => Scorecard::SUBJECT_COMPANY,
                'period_id' => $scorecard->period_id,
                'items' => [['perspective_id' => $perspectiveId, 'kpi_id' => $kpiId, 'weight' => 100]],
            ])->assertRedirect(route('performance.scorecards.show', $scorecardId));
        });

        $tenant->run(function () use ($scorecardId) {
            $this->assertSame('Renamed Scorecard', Scorecard::query()->find($scorecardId)->name);
        });

        $this->delete("/performance/scorecards/{$scorecardId}")->assertRedirect(route('performance.scorecards.index'));
        $tenant->run(function () use ($scorecardId) {
            $this->assertNull(Scorecard::query()->find($scorecardId));
        });
    }

    public function test_scorecard_index_filters_by_subject_type(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $this->makeScorecard($this->makePeriod());
        });

        $this->get('/performance/scorecards?subject_type='.Scorecard::SUBJECT_COMPANY)->assertOk()
            ->assertInertia(fn ($page) => $page->has('scorecards.data', 1));
    }

    // --- ScorecardScoringService direct branch coverage ---

    public function test_scoring_service_scores_kpi_and_okr_items_and_excludes_unscored_ones(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(ScorecardScoringService::class);
            $perspective = $this->makePerspective();
            $period = $this->makePeriod();

            $kpi = $this->makeKpiDefinition('Revenue', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->makeKpiValue($kpi, $period, ['actual_value' => 100]);

            $unscoredKpi = $this->makeKpiDefinition('No Data KPI'); // no Target/KpiValue at all

            $okr = $this->makeOkrObjective($this->makeOkrCycle());
            $this->makeKeyResult($okr, ['current_value' => 100, 'target_value' => 100]);

            $unscoredOkr = $this->makeOkrObjective($this->makeOkrCycle('Empty Cycle')); // zero key results

            $scorecard = $this->makeScorecard($period);
            $this->makeScorecardItem($scorecard, $perspective, ['kpi_id' => $kpi->id, 'weight' => 50]);
            $this->makeScorecardItem($scorecard, $perspective, ['kpi_id' => $unscoredKpi->id, 'weight' => 50]);
            $this->makeScorecardItem($scorecard, $perspective, ['okr_id' => $okr->id, 'weight' => 50]);
            $this->makeScorecardItem($scorecard, $perspective, ['okr_id' => $unscoredOkr->id, 'weight' => 50]);

            $scored = $service->score($scorecard);

            $this->assertSame(1, $scored['total_perspectives']);
            $this->assertSame(1, $scored['scored_perspectives']);

            $perspectiveResult = $scored['perspectives'][0];
            $this->assertSame(2, $perspectiveResult['scored_count']); // only the 2 with data
            $this->assertSame(4, $perspectiveResult['total_count']);
            $this->assertEqualsWithDelta(100.0, $perspectiveResult['score'], 0.001); // both scored items hit 100%
            $this->assertEqualsWithDelta(100.0, $scored['overall_score'], 0.001);
        });
    }

    public function test_scoring_service_returns_null_scores_when_scorecard_is_entirely_unscored(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(ScorecardScoringService::class);
            $perspective = $this->makePerspective();
            $kpi = $this->makeKpiDefinition(); // no target/value

            $scorecard = $this->makeScorecard($this->makePeriod());
            $this->makeScorecardItem($scorecard, $perspective, ['kpi_id' => $kpi->id, 'weight' => 100]);

            $scored = $service->score($scorecard);

            $this->assertNull($scored['perspectives'][0]['score']);
            $this->assertNull($scored['overall_score']);
            $this->assertSame(0, $scored['scored_perspectives']);
        });
    }

    public function test_scoring_service_achievement_score_covers_direction_and_zero_edge_cases(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(ScorecardScoringService::class);
            $period = $this->makePeriod();
            $perspective = $this->makePerspective();

            // Higher-is-better, zero target, non-negative actual -> fully met (100).
            $kpiZeroTargetHib = $this->makeKpiDefinition('Zero Target HIB', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($kpiZeroTargetHib, $period, ['target_value' => 0]);
            $this->makeKpiValue($kpiZeroTargetHib, $period, ['actual_value' => 5]);
            $scorecard1 = $this->makeScorecard($period, ['name' => 'SC1']);
            $this->makeScorecardItem($scorecard1, $perspective, ['kpi_id' => $kpiZeroTargetHib->id, 'weight' => 100]);
            $this->assertEqualsWithDelta(100.0, $service->score($scorecard1)['perspectives'][0]['score'], 0.001);

            // Higher-is-better, actual exceeds target by a lot -> clamped to 100, not 300.
            $kpiOverachieve = $this->makeKpiDefinition('Overachieve HIB', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $this->makeTarget($kpiOverachieve, $period, ['target_value' => 50]);
            $this->makeKpiValue($kpiOverachieve, $period, ['actual_value' => 150]);
            $scorecard2 = $this->makeScorecard($period, ['name' => 'SC2']);
            $this->makeScorecardItem($scorecard2, $perspective, ['kpi_id' => $kpiOverachieve->id, 'weight' => 100]);
            $this->assertEqualsWithDelta(100.0, $service->score($scorecard2)['perspectives'][0]['score'], 0.001);

            // Lower-is-better, zero (negligible) actual -> fully met (100).
            $kpiZeroActualLib = $this->makeKpiDefinition('Zero Actual LIB', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($kpiZeroActualLib, $period, ['target_value' => 10]);
            $this->makeKpiValue($kpiZeroActualLib, $period, ['actual_value' => 0]);
            $scorecard3 = $this->makeScorecard($period, ['name' => 'SC3']);
            $this->makeScorecardItem($scorecard3, $perspective, ['kpi_id' => $kpiZeroActualLib->id, 'weight' => 100]);
            $this->assertEqualsWithDelta(100.0, $service->score($scorecard3)['perspectives'][0]['score'], 0.001);

            // Lower-is-better, actual under target -> achievement ratio target/actual clamped to 100.
            $kpiUnderLib = $this->makeKpiDefinition('Under LIB', ['direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER]);
            $this->makeTarget($kpiUnderLib, $period, ['target_value' => 10]);
            $this->makeKpiValue($kpiUnderLib, $period, ['actual_value' => 40]);
            $scorecard4 = $this->makeScorecard($period, ['name' => 'SC4']);
            $this->makeScorecardItem($scorecard4, $perspective, ['kpi_id' => $kpiUnderLib->id, 'weight' => 100]);
            $this->assertEqualsWithDelta(25.0, $service->score($scorecard4)['perspectives'][0]['score'], 0.001);
        });
    }

    public function test_scoring_service_okr_score_clamps_negative_progress_to_zero(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(ScorecardScoringService::class);
            $perspective = $this->makePerspective();
            $okr = $this->makeOkrObjective($this->makeOkrCycle());
            // current below start on an increasing metric -> negative raw progress.
            $this->makeKeyResult($okr, ['metric_type' => OkrKeyResult::METRIC_NUMERIC, 'start_value' => 10, 'current_value' => 0, 'target_value' => 20]);

            $scorecard = $this->makeScorecard($this->makePeriod());
            $item = $this->makeScorecardItem($scorecard, $perspective, ['okr_id' => $okr->id, 'weight' => 100]);

            $scored = $service->score($scorecard);
            $scoredItem = collect($scored['perspectives'][0]['items'])->firstWhere('id', $item->id);

            $this->assertLessThan(0, $scoredItem['actual']); // raw progress uncapped
            $this->assertSame(0.0, $scoredItem['score']); // clamped score
        });
    }
}
