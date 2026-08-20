<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Services\WorkflowDefinitionService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3E — Version Control Engine. Per the spec's own text this is
 * "covered functionally in §3B" (the definition builder) plus §3C's
 * `resolvePublishedVersion()` — §3E adds no new code, only the enforcement
 * invariant that a running instance always finishes on the version it
 * started with. This file exists to make that invariant an explicit,
 * regression-proof assertion rather than something only incidentally true
 * of how §3B/§3C/§3D happen to be wired.
 */
class WorkflowVersionControlTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    /**
     * The single load-bearing claim of §3E: an in-flight instance's own
     * transitions are read from the version it started on, never
     * re-resolved against whatever is currently published. Proven by
     * publishing two versions with DIFFERENT shapes and checking the old
     * instance routes through v1's graph, not v2's.
     */
    public function test_an_instance_finishes_on_the_version_it_started_with_even_after_a_republish(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $definitions = app(WorkflowDefinitionService::class);
            $workflow = app(WorkflowService::class);
            $admin = $this->adminId();

            $definition = $definitions->create(['code' => 'demo.version_pin', 'name' => 'Version Pin'], $admin);
            $entryV1 = $definitions->addStep($definition, ['step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            $v1Only = $definitions->addStep($definition, ['step_code' => 'v1_only', 'type' => WrkflowStep::TYPE_TASK]);
            $definitions->addTransition($definition, ['from_step_id' => $entryV1->id, 'to_step_id' => $v1Only->id]);
            $definitions->publish($definition, $admin);

            $v1 = $definition->currentPublishedVersion();
            $instanceA = $workflow->start('demo.version_pin', null, null, []);
            $this->assertSame($v1->id, $instanceA->definition_version_id);

            // Fork v2 (auto-seeded as a copy of v1) and reshape it: v1's entry routed to
            // 'v1_only', v2's entry routes to a brand new 'v2_only' step instead — the two
            // versions now genuinely disagree about what comes after 'entry'.
            $v2 = $definitions->draftVersion($definition);
            $entryV2 = $v2->steps()->where('step_code', 'entry')->firstOrFail();
            $v1OnlyCopyOnV2 = $v2->steps()->where('step_code', 'v1_only')->firstOrFail();
            $definitions->deleteStep($v1OnlyCopyOnV2); // remove v2's copy of the old target...
            $v2Only = $definitions->addStep($definition, ['step_code' => 'v2_only', 'type' => WrkflowStep::TYPE_TASK]);
            $definitions->addTransition($definition, ['from_step_id' => $entryV2->id, 'to_step_id' => $v2Only->id]);
            $definitions->publish($definition, $admin);

            $definition->refresh();
            $v2 = $definition->currentPublishedVersion();
            $this->assertNotSame($v1->id, $v2->id);

            // A brand new instance pins to v2 and follows v2's graph.
            $instanceB = $workflow->start('demo.version_pin', null, null, []);
            $this->assertSame($v2->id, $instanceB->definition_version_id);
            $bEntryStep = WrkflowInstanceStep::query()->where('instance_id', $instanceB->id)->firstOrFail();
            $workflow->completeTask($bEntryStep, 'done', null, $admin);
            $this->assertNotNull(
                WrkflowInstanceStep::query()->where('instance_id', $instanceB->id)->where('step_id', $v2Only->id)->first(),
                "instance B (started after the republish) must advance through v2's graph"
            );

            // instanceA, still pinned to v1, must advance through v1's graph — never v2's —
            // even though v2 is now the currently-published version.
            $instanceA->refresh();
            $this->assertSame($v1->id, $instanceA->definition_version_id);
            $aEntryStep = WrkflowInstanceStep::query()->where('instance_id', $instanceA->id)->firstOrFail();
            $this->assertSame($entryV1->id, $aEntryStep->step_id);

            $workflow->completeTask($aEntryStep, 'done', null, $admin);

            $this->assertNotNull(
                WrkflowInstanceStep::query()->where('instance_id', $instanceA->id)->where('step_id', $v1Only->id)->first(),
                "instance A (started before the republish) must advance through v1's graph, not v2's"
            );
            $this->assertNull(
                WrkflowInstanceStep::query()->where('instance_id', $instanceA->id)->where('step_id', $v2Only->id)->first(),
                'instance A must never touch a step that only exists on v2'
            );
        });
    }

    /** §3E rule: unpublishing blocks new start() calls but never touches an instance already running on that version. */
    public function test_unpublish_blocks_new_instances_but_a_running_instance_keeps_running_to_completion(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $definitions = app(WorkflowDefinitionService::class);
            $workflow = app(WorkflowService::class);
            $admin = $this->adminId();

            $definition = $definitions->create(['code' => 'demo.unpublish_survives', 'name' => 'Unpublish Survives'], $admin);
            $only = $definitions->addStep($definition, ['step_code' => 'only_step', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            $definitions->publish($definition, $admin);

            $instance = $workflow->start('demo.unpublish_survives', null, null, []);
            $definitions->unpublish($definition);

            $definition->refresh();
            $this->assertSame(WrkflowDefinition::STATUS_UNPUBLISHED, $definition->status);
            $this->assertNull($definition->currentPublishedVersion());

            // Blocked for new instances...
            $this->expectException(WorkflowEngineException::class);

            try {
                $workflow->start('demo.unpublish_survives', null, null, []);
            } finally {
                // ...but the already-running instance is completely unaffected and can still
                // be driven to completion through the version it started on.
                $instance->refresh();
                $this->assertSame(WrkflowInstance::STATUS_RUNNING, $instance->status);

                $step = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $only->id)->firstOrFail();
                $workflow->completeTask($step, 'done', null, $admin);

                $instance->refresh();
                $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->status);
            }
        });
    }

    /** §5: `definition_version_id` is written once at start() and never appears in any later update — service-layer discipline, not a DB trigger. */
    public function test_definition_version_id_is_never_written_outside_of_start(): void
    {
        $service = file_get_contents(app_path('Modules/WNE/Services/WorkflowService.php'));
        $matches = [];
        preg_match_all('/definition_version_id/', $service, $matches);

        // Exactly one occurrence: the `create()` call inside start(). If this grows, a new
        // write path to this column was added — the §3E/§5 guarantee needs re-verifying.
        $this->assertSame(1, count($matches[0] ?? []));
    }
}
