<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C — Perspectives and Periods: the two shared master tables every other Performance engine reads from. */
class PerspectivePeriodTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_crud_a_perspective_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->get('/performance/perspectives')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Perspectives/Index'));
        $this->get('/performance/perspectives/create')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Perspectives/Create'));

        $this->post('/performance/perspectives', ['name' => 'Customer', 'description' => 'Client-facing metrics'])
            ->assertRedirect(route('performance.perspectives.index'));

        $perspectiveId = null;
        $tenant->run(function () use (&$perspectiveId) {
            $perspectiveId = Perspective::query()->where('name', 'Customer')->value('id');
        });

        $this->get("/performance/perspectives/{$perspectiveId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Perspectives/Edit')->where('perspective.name', 'Customer'));

        $this->put("/performance/perspectives/{$perspectiveId}", ['name' => 'Customer (renamed)', 'is_active' => true])
            ->assertRedirect(route('performance.perspectives.index'));

        $tenant->run(function () use ($perspectiveId) {
            $this->assertSame('Customer (renamed)', Perspective::query()->find($perspectiveId)->name);
        });

        $this->delete("/performance/perspectives/{$perspectiveId}")->assertRedirect(route('performance.perspectives.index'));
        $tenant->run(function () use ($perspectiveId) {
            $this->assertNull(Perspective::query()->find($perspectiveId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makePerspective('Bulk A')->id;
            $ids[] = $this->makePerspective('Bulk B')->id;
        });
        $this->delete('/performance/perspectives/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Perspective::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_perspective_store_rejects_duplicate_name_and_update_ignores_self(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $perspectiveId = null;
        $tenant->run(function () use (&$perspectiveId) {
            $perspectiveId = $this->makePerspective('Financial')->id;
        });

        $this->post('/performance/perspectives', ['name' => 'Financial'])->assertSessionHasErrors(['name']);

        $this->put("/performance/perspectives/{$perspectiveId}", ['name' => 'Financial'])->assertSessionDoesntHaveErrors();
    }

    public function test_perspective_index_filters_by_search_and_status(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $this->makePerspective('Findable Perspective');
            $this->makePerspective('Inactive Perspective', ['is_active' => false]);
        });

        $this->get('/performance/perspectives?search=Findable')->assertOk()
            ->assertInertia(fn ($page) => $page->has('perspectives.data', 1));
        $this->get('/performance/perspectives?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('perspectives.data', 1));
        $this->get('/performance/perspectives?sort=name&direction=asc')->assertOk();
    }

    public function test_perspective_delete_is_blocked_when_used_by_a_kpi(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $perspectiveId = null;
        $tenant->run(function () use (&$perspectiveId) {
            $perspective = $this->makePerspective();
            $perspectiveId = $perspective->id;
            $this->makeKpiDefinition('Revenue', ['perspective_id' => $perspective->id]);
        });

        $this->delete("/performance/perspectives/{$perspectiveId}")->assertSessionHasErrors(['name']);
    }

    public function test_admin_can_crud_a_period_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->get('/performance/periods')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/Periods/Index'));

        $this->post('/performance/periods', [
            'label' => 'Q1 2026',
            'period_type' => Period::TYPE_QUARTER,
            'year' => 2026,
            'quarter' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ])->assertRedirect(route('performance.periods.index'));

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $periodId = Period::query()->where('label', 'Q1 2026')->value('id');
        });

        $this->get("/performance/periods/{$periodId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Periods/Edit')->where('period.label', 'Q1 2026'));

        $this->put("/performance/periods/{$periodId}", [
            'label' => 'Q1 2026 (revised)',
            'period_type' => Period::TYPE_QUARTER,
            'year' => 2026,
            'quarter' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ])->assertRedirect(route('performance.periods.index'));

        $tenant->run(function () use ($periodId) {
            $this->assertSame('Q1 2026 (revised)', Period::query()->find($periodId)->label);
        });

        $this->delete("/performance/periods/{$periodId}")->assertRedirect(route('performance.periods.index'));
        $tenant->run(function () use ($periodId) {
            $this->assertNull(Period::query()->find($periodId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makePeriod('Bulk Period A')->id;
            $ids[] = $this->makePeriod('Bulk Period B')->id;
        });
        $this->delete('/performance/periods/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Period::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_period_store_rejects_duplicate_label_bad_dates_and_update_ignores_self(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $periodId = $this->makePeriod('2026')->id;
        });

        $this->post('/performance/periods', [
            'label' => '2026', 'period_type' => Period::TYPE_YEAR, 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertSessionHasErrors(['label']);

        $this->post('/performance/periods', [
            'label' => 'Bad Range', 'period_type' => Period::TYPE_YEAR, 'year' => 2026,
            'start_date' => '2026-12-31', 'end_date' => '2026-01-01',
        ])->assertSessionHasErrors(['end_date']);

        $this->put("/performance/periods/{$periodId}", [
            'label' => '2026', 'period_type' => Period::TYPE_YEAR, 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_period_index_filters_by_type_and_status(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $this->makePeriod('2026', ['period_type' => Period::TYPE_YEAR]);
            $this->makePeriod('2026-Q1', ['period_type' => Period::TYPE_QUARTER, 'quarter' => 1]);
            $inactive = $this->makePeriod('Inactive Period');
            $inactive->update(['is_active' => false]);
        });

        $this->get('/performance/periods?period_type='.Period::TYPE_QUARTER)->assertOk()
            ->assertInertia(fn ($page) => $page->has('periods.data', 1));
        $this->get('/performance/periods?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page->has('periods.data', 1));
    }

    public function test_period_delete_is_blocked_when_used_by_a_target(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $periodId = null;
        $tenant->run(function () use (&$periodId) {
            $period = $this->makePeriod();
            $periodId = $period->id;
            $kpi = $this->makeKpiDefinition();
            $this->makeTarget($kpi, $period);
        });

        $this->delete("/performance/periods/{$periodId}")->assertSessionHasErrors(['label']);
    }
}
