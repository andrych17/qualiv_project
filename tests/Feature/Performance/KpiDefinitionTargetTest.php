<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C — KPI Definitions (metric library) and Targets (the multi-level assignment mechanism). */
class KpiDefinitionTargetTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_crud_a_kpi_definition_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->get('/performance/kpi-definitions')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/KpiDefinitions/Index'));

        $perspectiveId = null;
        $tenant->run(function () use (&$perspectiveId) {
            $perspectiveId = $this->makePerspective()->id;
        });

        $this->post('/performance/kpi-definitions', [
            'name' => 'Deeds Executed',
            'unit' => KpiDefinition::UNIT_NUMBER,
            'direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER,
            'perspective_id' => $perspectiveId,
        ])->assertRedirect(route('performance.kpiDefinitions.index'));

        $kpiId = null;
        $tenant->run(function () use (&$kpiId) {
            $kpiId = KpiDefinition::query()->where('name', 'Deeds Executed')->value('id');
        });

        $this->get("/performance/kpi-definitions/{$kpiId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/KpiDefinitions/Edit')->where('kpi.name', 'Deeds Executed'));

        $this->put("/performance/kpi-definitions/{$kpiId}", [
            'name' => 'Deeds Executed (renamed)',
            'unit' => KpiDefinition::UNIT_NUMBER,
            'direction' => KpiDefinition::DIRECTION_LOWER_IS_BETTER,
        ])->assertRedirect(route('performance.kpiDefinitions.index'));

        $tenant->run(function () use ($kpiId) {
            $kpi = KpiDefinition::query()->find($kpiId);
            $this->assertSame('Deeds Executed (renamed)', $kpi->name);
            $this->assertSame(KpiDefinition::DIRECTION_LOWER_IS_BETTER, $kpi->direction);
        });

        $this->delete("/performance/kpi-definitions/{$kpiId}")->assertRedirect(route('performance.kpiDefinitions.index'));
        $tenant->run(function () use ($kpiId) {
            $this->assertNull(KpiDefinition::query()->find($kpiId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeKpiDefinition('Bulk A')->id;
            $ids[] = $this->makeKpiDefinition('Bulk B')->id;
        });
        $this->delete('/performance/kpi-definitions/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, KpiDefinition::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_kpi_definition_store_rejects_invalid_perspective(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/kpi-definitions', [
            'name' => 'Bad Perspective KPI',
            'unit' => KpiDefinition::UNIT_NUMBER,
            'direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER,
            'perspective_id' => 999999,
        ])->assertSessionHasErrors(['perspective_id']);
    }

    public function test_kpi_definition_index_filters_by_search_perspective_and_status(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $perspectiveId = null;
        $tenant->run(function () use (&$perspectiveId) {
            $perspective = $this->makePerspective();
            $perspectiveId = $perspective->id;
            $this->makeKpiDefinition('Findable KPI', ['perspective_id' => $perspectiveId]);
            $this->makeKpiDefinition('Inactive KPI', ['is_active' => false]);
        });

        $this->get('/performance/kpi-definitions?search=Findable')->assertOk()->assertInertia(fn ($page) => $page->has('kpis.data', 1));
        $this->get("/performance/kpi-definitions?perspective_id={$perspectiveId}")->assertOk()->assertInertia(fn ($page) => $page->has('kpis.data', 1));
        $this->get('/performance/kpi-definitions?status=inactive')->assertOk()->assertInertia(fn ($page) => $page->has('kpis.data', 1));
        $this->get('/performance/kpi-definitions?sort=name&direction=asc')->assertOk();
    }

    public function test_kpi_definition_delete_is_blocked_when_it_has_targets(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $kpiId = null;
        $tenant->run(function () use (&$kpiId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $this->makeTarget($kpi, $this->makePeriod());
        });

        $this->delete("/performance/kpi-definitions/{$kpiId}")->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_crud_a_target(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpiId = $this->makeKpiDefinition()->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->get('/performance/targets')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Targets/Index'));

        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'target_value' => 100,
            'stretch_value' => 120,
        ])->assertRedirect(route('performance.targets.index'));

        $targetId = null;
        $tenant->run(function () use (&$targetId, $kpiId) {
            $targetId = Target::query()->where('kpi_id', $kpiId)->value('id');
        });

        $this->get("/performance/targets/{$targetId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Targets/Edit')->where('target.target_value', 100));

        $this->put("/performance/targets/{$targetId}", [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'target_value' => 150,
        ])->assertRedirect(route('performance.targets.index'));

        $tenant->run(function () use ($targetId) {
            $this->assertEqualsWithDelta(150.0, (float) Target::query()->find($targetId)->target_value, 0.001);
        });

        $this->delete("/performance/targets/{$targetId}")->assertRedirect(route('performance.targets.index'));
        $tenant->run(function () use ($targetId) {
            $this->assertNull(Target::query()->find($targetId));
        });
    }

    public function test_target_store_rejects_inactive_kpi_and_invalid_refs(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$inactiveKpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$inactiveKpiId, &$periodId) {
            $inactiveKpiId = $this->makeKpiDefinition('Inactive KPI', ['is_active' => false])->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/targets', [
            'kpi_id' => $inactiveKpiId,
            'subject_type' => Target::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'target_value' => 100,
        ])->assertSessionHasErrors(['kpi_id']);

        $this->post('/performance/targets', [
            'kpi_id' => 999999,
            'subject_type' => Target::SUBJECT_COMPANY,
            'period_id' => 999999,
            'target_value' => 100,
        ])->assertSessionHasErrors(['kpi_id', 'period_id']);
    }

    public function test_target_store_rejects_duplicate_assignment_and_missing_subject_id(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period);
        });

        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'target_value' => 200,
        ])->assertSessionHasErrors(['subject_id']);

        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_EMPLOYEE,
            'period_id' => $periodId,
            'target_value' => 200,
        ])->assertSessionHasErrors(['subject_id']);
    }

    public function test_target_store_rejects_invalid_org_unit_and_employee_subject(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpiId = $this->makeKpiDefinition()->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_ORG_UNIT,
            'subject_id' => 999999,
            'period_id' => $periodId,
            'target_value' => 100,
        ])->assertSessionHasErrors(['subject_id']);

        $this->post('/performance/targets', [
            'kpi_id' => $kpiId,
            'subject_type' => Target::SUBJECT_EMPLOYEE,
            'subject_id' => 999999,
            'period_id' => $periodId,
            'target_value' => 100,
        ])->assertSessionHasErrors(['subject_id']);
    }

    public function test_target_index_filters_by_kpi_period_and_subject_type(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period);
        });

        $this->get("/performance/targets?kpi_id={$kpiId}")->assertOk()->assertInertia(fn ($page) => $page->has('targets.data', 1));
        $this->get("/performance/targets?period_id={$periodId}")->assertOk()->assertInertia(fn ($page) => $page->has('targets.data', 1));
        $this->get('/performance/targets?subject_type='.Target::SUBJECT_COMPANY)->assertOk()->assertInertia(fn ($page) => $page->has('targets.data', 1));
        $this->get('/performance/targets?sort=target_value&direction=desc')->assertOk();
    }
}
