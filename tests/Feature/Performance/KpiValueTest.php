<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\KpiValue;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D KPI Actuals Capture — MVP manual entry, and the KpiValueRecorded event's two listeners (§3G breach notification, §3I target-hit achievement). */
class KpiValueTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_crud_a_kpi_value(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpiId = $this->makeKpiDefinition()->id;
            $periodId = $this->makePeriod()->id;
        });

        $this->get('/performance/kpi-values')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/KpiValues/Index'));

        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 88,
        ])->assertRedirect(route('performance.kpiValues.index'));

        $valueId = null;
        $tenant->run(function () use (&$valueId, $kpiId) {
            $value = KpiValue::query()->where('kpi_id', $kpiId)->first();
            $this->assertEqualsWithDelta(88.0, (float) $value->actual_value, 0.001);
            $this->assertSame(KpiValue::SOURCE_MANUAL, $value->source);
            $this->assertNotNull($value->entered_by);
            $valueId = $value->id;
        });

        $this->get("/performance/kpi-values/{$valueId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/KpiValues/Edit'));

        $this->put("/performance/kpi-values/{$valueId}", [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 95,
        ])->assertRedirect(route('performance.kpiValues.index'));

        $tenant->run(function () use ($valueId) {
            $this->assertEqualsWithDelta(95.0, (float) KpiValue::query()->find($valueId)->actual_value, 0.001);
        });

        $this->delete("/performance/kpi-values/{$valueId}")->assertRedirect(route('performance.kpiValues.index'));
        $tenant->run(function () use ($valueId) {
            $this->assertNull(KpiValue::query()->find($valueId));
        });
    }

    public function test_store_rejects_duplicate_entry_and_invalid_refs(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeKpiValue($kpi, $period);
        });

        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 10,
        ])->assertSessionHasErrors(['subject_id']);

        $this->post('/performance/kpi-values', [
            'kpi_id' => 999999,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => 999999,
            'actual_value' => 10,
        ])->assertSessionHasErrors(['kpi_id', 'period_id']);
    }

    public function test_kpi_value_index_filters(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeKpiValue($kpi, $period);
        });

        $this->get("/performance/kpi-values?kpi_id={$kpiId}")->assertOk()->assertInertia(fn ($page) => $page->has('values.data', 1));
        $this->get("/performance/kpi-values?period_id={$periodId}")->assertOk()->assertInertia(fn ($page) => $page->has('values.data', 1));
        $this->get('/performance/kpi-values?subject_type='.KpiValue::SUBJECT_COMPANY)->assertOk()->assertInertia(fn ($page) => $page->has('values.data', 1));
        $this->get('/performance/kpi-values?sort=actual_value&direction=desc')->assertOk();
    }

    public function test_recording_a_breaching_value_fires_a_variance_breach_notification(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition('Revenue', ['direction' => KpiDefinition::DIRECTION_HIGHER_IS_BETTER]);
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
        });

        // 30% shortfall against a higher-is-better target — well past the 15% breach threshold.
        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 70,
        ])->assertRedirect();

        Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->category === 'performance.variance_breach');
    }

    public function test_recording_an_on_track_value_does_not_notify(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
        });

        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 100,
        ])->assertRedirect();

        Event::assertNotDispatched(NotificationRequested::class);
    }

    public function test_recording_an_on_target_value_auto_awards_a_target_hit_badge(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId] = [null, null];
        $tenant->run(function () use (&$kpiId, &$periodId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->makeBadgeDefinition('Target Champion', ['trigger_type' => BadgeDefinition::TRIGGER_TARGET_HIT]);
        });

        $this->post('/performance/kpi-values', [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 100,
        ])->assertRedirect();

        $tenant->run(function () use ($kpiId) {
            $this->assertSame(1, Achievement::query()->where('kpi_id', $kpiId)->count());
            $achievement = Achievement::query()->where('kpi_id', $kpiId)->first();
            $this->assertNull($achievement->awarded_by);
        });

        Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->category === 'performance.achievement_earned');
    }

    public function test_repeated_target_hits_do_not_duplicate_the_achievement(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        [$kpiId, $periodId, $valueId] = [null, null, null];
        $tenant->run(function () use (&$kpiId, &$periodId, &$valueId) {
            $kpi = $this->makeKpiDefinition();
            $kpiId = $kpi->id;
            $period = $this->makePeriod();
            $periodId = $period->id;
            $this->makeTarget($kpi, $period, ['target_value' => 100]);
            $this->makeBadgeDefinition('Target Champion', ['trigger_type' => BadgeDefinition::TRIGGER_TARGET_HIT]);
            $valueId = $this->makeKpiValue($kpi, $period, ['actual_value' => 100])->id;
        });

        // Re-saving the same on-target value a second time must not mint a second Achievement row.
        $this->put("/performance/kpi-values/{$valueId}", [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 100,
        ])->assertRedirect();

        $this->put("/performance/kpi-values/{$valueId}", [
            'kpi_id' => $kpiId,
            'subject_type' => KpiValue::SUBJECT_COMPANY,
            'period_id' => $periodId,
            'actual_value' => 101,
        ])->assertRedirect();

        $tenant->run(function () use ($kpiId) {
            $this->assertSame(1, Achievement::query()->where('kpi_id', $kpiId)->count());
        });
    }
}
