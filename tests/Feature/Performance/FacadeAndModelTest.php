<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Modules\Performance\Models\OkrObjective;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Model relation coverage for paths no controller's eager-load already touches. */
class FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_perspective_period_and_kpi_definition_relations(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $perspective = $this->makePerspective();
            $kpi = $this->makeKpiDefinition('Relation KPI', ['perspective_id' => $perspective->id]);
            $this->assertTrue($perspective->kpiDefinitions->contains('id', $kpi->id));

            $period = $this->makePeriod();
            $target = $this->makeTarget($kpi, $period);
            $this->assertTrue($period->targets->contains('id', $target->id));
            $this->assertTrue($kpi->targets->contains('id', $target->id));
        });
    }

    public function test_target_created_by_relation(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $target = $this->makeTarget($kpi, $period, []);
            $target->update(['created_by' => $adminId]);

            $this->assertSame($adminId, $target->fresh()->createdBy->id);
        });
    }

    public function test_okr_cycle_objectives_and_objective_children_relations(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $cycle = $this->makeOkrCycle();
            $parent = $this->makeOkrObjective($cycle, ['objective_text' => 'Parent']);
            $child = $this->makeOkrObjective($cycle, ['objective_text' => 'Child', 'parent_okr_id' => $parent->id]);

            $this->assertTrue($cycle->objectives->contains('id', $parent->id));
            $this->assertTrue($parent->fresh()->children->contains('id', $child->id));
            $this->assertSame(OkrObjective::SUBJECT_COMPANY, $parent->subject_type);
        });
    }

    public function test_budget_owner_prior_version_and_created_by_relations(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $original = $this->makeBudget();
            $original->update(['owner_id' => $adminId, 'created_by' => $adminId]);
            $revision = $this->makeBudget(['prior_version_id' => $original->id, 'version_no' => 2]);

            $this->assertSame($adminId, $original->fresh()->owner->id);
            $this->assertSame($adminId, $original->fresh()->createdBy->id);
            $this->assertSame($original->id, $revision->priorVersion->id);
        });
    }

    public function test_forecast_root_forecast_and_created_by_relations(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $kpi = $this->makeKpiDefinition();
            $period = $this->makePeriod();
            $root = $this->makeForecast($period, ['kpi_id' => $kpi->id]);
            $root->update(['created_by' => $adminId]);
            $revision = $this->makeForecast($period, ['kpi_id' => $kpi->id, 'root_forecast_id' => $root->id, 'version_no' => 2]);

            $this->assertSame($adminId, $root->fresh()->createdBy->id);
            $this->assertSame($root->id, $revision->rootForecast->id);
        });
    }

    public function test_scorecard_item_scorecard_relation_and_scorecard_created_by(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $adminId = User::query()->where('email', 'admin@nusaevo.com')->value('id');
            $scorecard = $this->makeScorecard($this->makePeriod());
            $scorecard->update(['created_by' => $adminId]);
            $item = $this->makeScorecardItem($scorecard, $this->makePerspective(), ['kpi_id' => $this->makeKpiDefinition()->id]);

            $this->assertSame($scorecard->id, $item->scorecard->id);
            $this->assertSame($adminId, $scorecard->fresh()->createdBy->id);
        });
    }

    public function test_badge_definition_achievements_relation(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $badge = $this->makeBadgeDefinition();
            $achievement = $this->makeAchievement($badge);

            $this->assertTrue($badge->fresh()->achievements->contains('id', $achievement->id));
        });
    }
}
