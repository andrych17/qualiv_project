<?php

namespace Tests\Feature\Performance;

use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrKeyResult;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Services\OkrObjectiveService;
use App\Modules\Performance\Services\OkrProgressService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpPerformance;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E OKR Management — cycles, objectives with inline key results, alignment cycle-guard, status transitions and the completion achievement. */
class OkrTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpPerformance;
    use SetsUpTenant;

    public function test_admin_can_crud_an_okr_cycle_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $this->get('/performance/okr-cycles')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/OkrCycles/Index'));

        $this->post('/performance/okr-cycles', ['label' => '2026 Q2', 'start_date' => '2026-04-01', 'end_date' => '2026-06-30'])
            ->assertRedirect(route('performance.okrCycles.index'));

        $cycleId = null;
        $tenant->run(function () use (&$cycleId) {
            $cycleId = OkrCycle::query()->where('label', '2026 Q2')->value('id');
        });

        $this->put("/performance/okr-cycles/{$cycleId}", ['label' => '2026 Q2 (revised)', 'start_date' => '2026-04-01', 'end_date' => '2026-06-30'])
            ->assertRedirect(route('performance.okrCycles.index'));

        $tenant->run(function () use ($cycleId) {
            $this->assertSame('2026 Q2 (revised)', OkrCycle::query()->find($cycleId)->label);
        });

        $this->delete("/performance/okr-cycles/{$cycleId}")->assertRedirect(route('performance.okrCycles.index'));
        $tenant->run(function () use ($cycleId) {
            $this->assertNull(OkrCycle::query()->find($cycleId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeOkrCycle('Bulk Cycle A')->id;
            $ids[] = $this->makeOkrCycle('Bulk Cycle B')->id;
        });
        $this->delete('/performance/okr-cycles/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, OkrCycle::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_okr_cycle_delete_is_blocked_when_it_has_objectives(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $cycleId = null;
        $tenant->run(function () use (&$cycleId) {
            $cycle = $this->makeOkrCycle();
            $cycleId = $cycle->id;
            $this->makeOkrObjective($cycle);
        });

        $this->delete("/performance/okr-cycles/{$cycleId}")->assertSessionHasErrors(['label']);
    }

    public function test_admin_can_create_an_objective_with_key_results(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $cycleId = null;
        $tenant->run(function () use (&$cycleId) {
            $cycleId = $this->makeOkrCycle()->id;
        });

        $this->get('/performance/okr-objectives/create')->assertOk()->assertInertia(fn ($page) => $page->component('Performance/OkrObjectives/Create'));

        $this->post('/performance/okr-objectives', [
            'cycle_id' => $cycleId,
            'subject_type' => OkrObjective::SUBJECT_COMPANY,
            'objective_text' => 'Accelerate digital turnaround',
            'key_results' => [
                ['description' => 'Ship self-service portal', 'metric_type' => OkrKeyResult::METRIC_PERCENT, 'start_value' => 0, 'current_value' => 40, 'target_value' => 100, 'weight' => 60],
                ['description' => 'Zero overdue submissions', 'metric_type' => OkrKeyResult::METRIC_BOOLEAN, 'current_value' => 0, 'target_value' => 1, 'weight' => 40],
            ],
        ])->assertRedirect(route('performance.okrObjectives.index'));

        $tenant->run(function () use ($cycleId) {
            $objective = OkrObjective::query()->where('cycle_id', $cycleId)->with('keyResults')->first();
            $this->assertNotNull($objective);
            $this->assertCount(2, $objective->keyResults);
        });
    }

    public function test_objective_update_replaces_key_results_and_skips_incomplete_rows(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $objectiveId = null;
        $tenant->run(function () use (&$objectiveId) {
            $objective = $this->makeOkrObjective($this->makeOkrCycle());
            $this->makeKeyResult($objective, ['description' => 'Old KR']);
            $objectiveId = $objective->id;
        });

        $tenant->run(function () use ($objectiveId) {
            $objective = OkrObjective::query()->find($objectiveId);

            $this->put("/performance/okr-objectives/{$objectiveId}", [
                'cycle_id' => $objective->cycle_id,
                'subject_type' => OkrObjective::SUBJECT_COMPANY,
                'objective_text' => 'Updated objective',
                'key_results' => [
                    ['description' => 'New KR', 'metric_type' => OkrKeyResult::METRIC_NUMERIC, 'current_value' => 5, 'target_value' => 10],
                    ['description' => '', 'metric_type' => OkrKeyResult::METRIC_NUMERIC, 'target_value' => 10], // incomplete — skipped
                ],
            ])->assertRedirect(route('performance.okrObjectives.index'));
        });

        $tenant->run(function () use ($objectiveId) {
            $keyResults = OkrKeyResult::query()->where('okr_id', $objectiveId)->get();
            $this->assertCount(1, $keyResults);
            $this->assertSame('New KR', $keyResults->first()->description);
        });
    }

    public function test_objective_store_rejects_self_and_circular_parent(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$cycleId, $grandparentId, $parentId] = [null, null, null];
        $tenant->run(function () use (&$cycleId, &$grandparentId, &$parentId) {
            $cycle = $this->makeOkrCycle();
            $cycleId = $cycle->id;
            $grandparent = $this->makeOkrObjective($cycle, ['objective_text' => 'Grandparent']);
            $grandparentId = $grandparent->id;
            $parentId = $this->makeOkrObjective($cycle, ['objective_text' => 'Parent', 'parent_okr_id' => $grandparent->id])->id;
        });

        // Moving "Grandparent" under its own descendant "Parent" would create a cycle.
        $this->put("/performance/okr-objectives/{$grandparentId}", [
            'cycle_id' => $cycleId,
            'subject_type' => OkrObjective::SUBJECT_COMPANY,
            'objective_text' => 'Grandparent',
            'parent_okr_id' => $parentId,
        ])->assertSessionHasErrors();

        $this->put("/performance/okr-objectives/{$grandparentId}", [
            'cycle_id' => $cycleId,
            'subject_type' => OkrObjective::SUBJECT_COMPANY,
            'objective_text' => 'Grandparent',
            'parent_okr_id' => $grandparentId,
        ])->assertSessionHasErrors();
    }

    public function test_objective_store_rejects_invalid_cycle_parent_and_subject(): void
    {
        $this->loginAsPerformanceAdmin();

        $this->post('/performance/okr-objectives', [
            'cycle_id' => 999999,
            'subject_type' => OkrObjective::SUBJECT_ORG_UNIT,
            'subject_id' => 999999,
            'objective_text' => 'Bad refs',
            'parent_okr_id' => 999999,
        ])->assertSessionHasErrors(['cycle_id', 'parent_okr_id', 'subject_id']);
    }

    public function test_objective_index_and_delete(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        [$cycleId, $objectiveId] = [null, null];
        $tenant->run(function () use (&$cycleId, &$objectiveId) {
            $cycle = $this->makeOkrCycle();
            $cycleId = $cycle->id;
            $objectiveId = $this->makeOkrObjective($cycle)->id;
        });

        $this->get("/performance/okr-objectives?cycle_id={$cycleId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/OkrObjectives/Index')->has('objectives', 1));

        $this->delete("/performance/okr-objectives/{$objectiveId}")->assertRedirect(route('performance.okrObjectives.index'));
        $tenant->run(function () use ($objectiveId) {
            $this->assertNull(OkrObjective::query()->find($objectiveId));
        });
    }

    public function test_update_status_transitions_and_rejects_invalid_status(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $objectiveId = null;
        $tenant->run(function () use (&$objectiveId) {
            $objectiveId = $this->makeOkrObjective($this->makeOkrCycle())->id;
        });

        $this->patch("/performance/okr-objectives/{$objectiveId}/status", ['status' => OkrObjective::STATUS_AT_RISK])
            ->assertRedirect();

        $tenant->run(function () use ($objectiveId) {
            $this->assertSame(OkrObjective::STATUS_AT_RISK, OkrObjective::query()->find($objectiveId)->status);
        });

        $this->patch("/performance/okr-objectives/{$objectiveId}/status", ['status' => 'not_a_real_status'])
            ->assertSessionHasErrors(['status']);
    }

    public function test_update_status_service_layer_rejects_invalid_status_bypassing_form_request(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $objective = $this->makeOkrObjective($this->makeOkrCycle());
            $this->expectException(ValidationException::class);
            app(OkrObjectiveService::class)->updateStatus($objective, 'bogus');
        });
    }

    public function test_transitioning_status_to_completed_auto_awards_and_does_not_duplicate(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        $objectiveId = null;
        $tenant->run(function () use (&$objectiveId) {
            $objectiveId = $this->makeOkrObjective($this->makeOkrCycle())->id;
            $this->makeBadgeDefinition('OKR Champion', ['trigger_type' => BadgeDefinition::TRIGGER_OKR_COMPLETED]);
        });

        $this->patch("/performance/okr-objectives/{$objectiveId}/status", ['status' => OkrObjective::STATUS_COMPLETED])->assertRedirect();

        $tenant->run(function () use ($objectiveId) {
            $this->assertSame(1, Achievement::query()->where('okr_id', $objectiveId)->count());
        });

        Event::assertDispatched(NotificationRequested::class, fn (NotificationRequested $e) => $e->category === 'performance.achievement_earned');

        // Re-saving the objective (still completed) must not fire OkrObjectiveCompleted again.
        $tenant->run(function () use ($objectiveId) {
            $objective = OkrObjective::query()->find($objectiveId);
            app(OkrObjectiveService::class)->update($objective, [
                'cycle_id' => $objective->cycle_id,
                'subject_type' => $objective->subject_type,
                'objective_text' => 'Still completed',
                'status' => OkrObjective::STATUS_COMPLETED,
            ]);

            $this->assertSame(1, Achievement::query()->where('okr_id', $objectiveId)->count());
        });
    }

    public function test_creating_an_objective_already_completed_still_awards(): void
    {
        Event::fake([NotificationRequested::class]);
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $this->makeBadgeDefinition('OKR Champion', ['trigger_type' => BadgeDefinition::TRIGGER_OKR_COMPLETED]);
        });

        $cycleId = null;
        $tenant->run(function () use (&$cycleId) {
            $cycleId = $this->makeOkrCycle()->id;
        });

        $this->post('/performance/okr-objectives', [
            'cycle_id' => $cycleId,
            'subject_type' => OkrObjective::SUBJECT_COMPANY,
            'objective_text' => 'Backfilled, already done',
            'status' => OkrObjective::STATUS_COMPLETED,
        ])->assertRedirect();

        $tenant->run(function () {
            $this->assertSame(1, Achievement::query()->where('badge_id', BadgeDefinition::query()->value('id'))->count());
        });
    }

    // --- OkrProgressService direct branch coverage ---

    public function test_progress_service_key_result_progress_covers_every_branch(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(OkrProgressService::class);
            $objective = $this->makeOkrObjective($this->makeOkrCycle());

            $numeric = $this->makeKeyResult($objective, ['metric_type' => OkrKeyResult::METRIC_NUMERIC, 'start_value' => 0, 'current_value' => 25, 'target_value' => 100]);
            $this->assertEqualsWithDelta(25.0, $service->keyResultProgress($numeric), 0.001);

            // Decreasing metric (churn-style): current below start, target below start too.
            $decreasing = $this->makeKeyResult($objective, ['metric_type' => OkrKeyResult::METRIC_PERCENT, 'start_value' => 8, 'current_value' => 5, 'target_value' => 2]);
            $this->assertEqualsWithDelta(50.0, $service->keyResultProgress($decreasing), 0.001);

            $booleanTrue = $this->makeKeyResult($objective, ['metric_type' => OkrKeyResult::METRIC_BOOLEAN, 'current_value' => 1, 'target_value' => 1]);
            $this->assertEqualsWithDelta(100.0, $service->keyResultProgress($booleanTrue), 0.001);

            $booleanFalse = $this->makeKeyResult($objective, ['metric_type' => OkrKeyResult::METRIC_BOOLEAN, 'current_value' => 0, 'target_value' => 1]);
            $this->assertEqualsWithDelta(0.0, $service->keyResultProgress($booleanFalse), 0.001);

            $noRange = $this->makeKeyResult($objective, ['metric_type' => OkrKeyResult::METRIC_MILESTONE, 'start_value' => 5, 'current_value' => 5, 'target_value' => 5]);
            $this->assertNull($service->keyResultProgress($noRange));
        });
    }

    public function test_progress_service_objective_progress_weighted_average_and_null_cases(): void
    {
        $tenant = $this->loginAsPerformanceAdmin();

        $tenant->run(function () {
            $service = app(OkrProgressService::class);

            $empty = $this->makeOkrObjective($this->makeOkrCycle());
            $this->assertNull($service->objectiveProgress($empty));

            $zeroWeightOnly = $this->makeOkrObjective($this->makeOkrCycle());
            $this->makeKeyResult($zeroWeightOnly, ['current_value' => 50, 'target_value' => 100, 'weight' => 0]);
            $this->assertNull($service->objectiveProgress($zeroWeightOnly));

            $weighted = $this->makeOkrObjective($this->makeOkrCycle());
            $this->makeKeyResult($weighted, ['current_value' => 100, 'target_value' => 100, 'weight' => 75]); // 100% progress
            $this->makeKeyResult($weighted, ['current_value' => 0, 'target_value' => 100, 'weight' => 25]);   // 0% progress
            // A no-range KR is excluded entirely, not counted as 0 weight-wise.
            $this->makeKeyResult($weighted, ['start_value' => 5, 'current_value' => 5, 'target_value' => 5, 'weight' => 1000]);

            $this->assertEqualsWithDelta(75.0, $service->objectiveProgress($weighted), 0.001);
        });
    }
}
