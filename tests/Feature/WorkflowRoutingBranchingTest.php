<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3D — Routing & Branching Engine, built directly against
 * WorkflowService/Eloquent (§3B's builder doesn't author condition/parallel
 * step types yet). Every graph ends in plain `task` steps rather than
 * `notify` — `notify` has no executor until §3I, so using it as a terminal
 * would throw for reasons unrelated to what these tests check.
 */
class WorkflowRoutingBranchingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    /** @return array{0: WrkflowDefinition, 1: WrkflowVersion} */
    private function publishedDefinition(string $code): array
    {
        $definition = WrkflowDefinition::query()->create(['code' => $code, 'name' => $code, 'status' => WrkflowDefinition::STATUS_PUBLISHED]);
        $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $this->adminId()]);

        return [$definition, $version];
    }

    private function instanceStepFor(WrkflowInstance $instance, WrkflowStep $step): ?WrkflowInstanceStep
    {
        return WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $step->id)->first();
    }

    public function test_conditional_branch_routes_by_the_prior_steps_own_decision(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.decision_branch');

            $approve = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true]);
            $approved = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'approved', 'type' => WrkflowStep::TYPE_TASK]);
            $rejected = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'rejected', 'type' => WrkflowStep::TYPE_TASK]);

            $approve->outgoingTransitions()->create(['to_step_id' => $approved->id, 'condition_expression' => ['field' => 'decision', 'op' => '=', 'value' => 'approve'], 'seq' => 0]);
            $approve->outgoingTransitions()->create(['to_step_id' => $rejected->id, 'condition_expression' => null, 'seq' => 1]);

            $workflow = app(WorkflowService::class);

            $approvedInstance = $workflow->start('demo.decision_branch', null, null, []);
            $workflow->completeTask($this->instanceStepFor($approvedInstance, $approve), 'approve', null, $this->adminId());
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($approvedInstance, $approved)?->status);
            $this->assertNull($this->instanceStepFor($approvedInstance, $rejected));

            $rejectedInstance = $workflow->start('demo.decision_branch', null, null, []);
            $workflow->completeTask($this->instanceStepFor($rejectedInstance, $approve), 'reject', null, $this->adminId());
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($rejectedInstance, $rejected)?->status);
            $this->assertNull($this->instanceStepFor($rejectedInstance, $approved));
        });
    }

    public function test_condition_step_auto_advances_and_evaluates_numeric_list_and_substring_operators(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.multi_op_branch');

            $entry = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            $check = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'check', 'type' => WrkflowStep::TYPE_CONDITION]);
            $big = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'big', 'type' => WrkflowStep::TYPE_TASK]);
            $tagged = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'tagged', 'type' => WrkflowStep::TYPE_TASK]);
            $substr = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'substr', 'type' => WrkflowStep::TYPE_TASK]);
            $fallback = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'fallback', 'type' => WrkflowStep::TYPE_TASK]);

            $entry->outgoingTransitions()->create(['to_step_id' => $check->id]);
            $check->outgoingTransitions()->create(['to_step_id' => $big->id, 'condition_expression' => ['field' => 'amount', 'op' => '>', 'value' => 5000000], 'seq' => 0]);
            $check->outgoingTransitions()->create(['to_step_id' => $tagged->id, 'condition_expression' => ['field' => 'tag', 'op' => 'in', 'value' => 'urgent,vip'], 'seq' => 1]);
            $check->outgoingTransitions()->create(['to_step_id' => $substr->id, 'condition_expression' => ['field' => 'note', 'op' => 'contains', 'value' => 'refund'], 'seq' => 2]);
            $check->outgoingTransitions()->create(['to_step_id' => $fallback->id, 'condition_expression' => null, 'seq' => 3]);

            $workflow = app(WorkflowService::class);

            $runToCheck = function (array $payload) use ($workflow, $entry) {
                $instance = $workflow->start('demo.multi_op_branch', null, null, $payload);
                $workflow->completeTask($this->instanceStepFor($instance, $entry), 'done', null, $this->adminId());

                return $instance;
            };

            $viaAmount = $runToCheck(['amount' => 7500000]);
            $this->assertNotNull($this->instanceStepFor($viaAmount, $big));
            $this->assertNull($this->instanceStepFor($viaAmount, $tagged));

            $viaList = $runToCheck(['amount' => 100, 'tag' => 'vip']);
            $this->assertNotNull($this->instanceStepFor($viaList, $tagged));
            $this->assertNull($this->instanceStepFor($viaList, $big));

            $viaSubstring = $runToCheck(['amount' => 100, 'note' => 'customer requested a refund']);
            $this->assertNotNull($this->instanceStepFor($viaSubstring, $substr));

            $viaDefault = $runToCheck(['amount' => 100]);
            $this->assertNotNull($this->instanceStepFor($viaDefault, $fallback));
            $this->assertNull($this->instanceStepFor($viaDefault, $big));
            $this->assertNull($this->instanceStepFor($viaDefault, $tagged));
            $this->assertNull($this->instanceStepFor($viaDefault, $substr));

            // The 'check' step itself never waits for a human — it's completed the moment it's begun.
            $this->assertSame(WrkflowInstanceStep::STATUS_COMPLETED, $this->instanceStepFor($viaDefault, $check)?->status);
        });
    }

    public function test_parallel_split_join_all_waits_for_every_branch(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.split_join_all');

            $split = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'split', 'type' => WrkflowStep::TYPE_PARALLEL_SPLIT, 'is_entry_step' => true]);
            $branchA = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_a', 'type' => WrkflowStep::TYPE_TASK]);
            $branchB = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_b', 'type' => WrkflowStep::TYPE_TASK]);
            $join = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'join', 'type' => WrkflowStep::TYPE_PARALLEL_JOIN, 'config' => ['join_rule' => 'all']]);
            $final = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'final', 'type' => WrkflowStep::TYPE_TASK]);

            $split->outgoingTransitions()->create(['to_step_id' => $branchA->id]);
            $split->outgoingTransitions()->create(['to_step_id' => $branchB->id]);
            $branchA->outgoingTransitions()->create(['to_step_id' => $join->id]);
            $branchB->outgoingTransitions()->create(['to_step_id' => $join->id]);
            $join->outgoingTransitions()->create(['to_step_id' => $final->id]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.split_join_all', null, null, []);

            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($instance, $branchA)?->status);
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($instance, $branchB)?->status);
            $this->assertNull($this->instanceStepFor($instance, $join));

            $workflow->completeTask($this->instanceStepFor($instance, $branchA), 'done', null, $this->adminId());
            $this->assertNull($this->instanceStepFor($instance, $join)); // only one of two branches arrived
            $this->assertSame(WrkflowInstance::STATUS_RUNNING, $instance->fresh()->status);

            $workflow->completeTask($this->instanceStepFor($instance, $branchB), 'done', null, $this->adminId());
            $this->assertSame(WrkflowInstanceStep::STATUS_COMPLETED, $this->instanceStepFor($instance, $join)?->status);
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($instance, $final)?->status);
            $this->assertSame(
                1,
                WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $join->id)->count()
            );
        });
    }

    public function test_parallel_split_join_any_fires_on_first_branch_and_ignores_later_arrivals(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.split_join_any');

            $split = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'split', 'type' => WrkflowStep::TYPE_PARALLEL_SPLIT, 'is_entry_step' => true]);
            $branchA = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_a', 'type' => WrkflowStep::TYPE_TASK]);
            $branchB = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_b', 'type' => WrkflowStep::TYPE_TASK]);
            $join = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'join', 'type' => WrkflowStep::TYPE_PARALLEL_JOIN, 'config' => ['join_rule' => 'any']]);
            $final = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'final', 'type' => WrkflowStep::TYPE_TASK]);

            $split->outgoingTransitions()->create(['to_step_id' => $branchA->id]);
            $split->outgoingTransitions()->create(['to_step_id' => $branchB->id]);
            $branchA->outgoingTransitions()->create(['to_step_id' => $join->id]);
            $branchB->outgoingTransitions()->create(['to_step_id' => $join->id]);
            $join->outgoingTransitions()->create(['to_step_id' => $final->id]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.split_join_any', null, null, []);

            $workflow->completeTask($this->instanceStepFor($instance, $branchA), 'done', null, $this->adminId());
            $this->assertSame(WrkflowInstanceStep::STATUS_COMPLETED, $this->instanceStepFor($instance, $join)?->status);
            $this->assertSame(WrkflowInstanceStep::STATUS_IN_PROGRESS, $this->instanceStepFor($instance, $final)?->status);

            // branch_b finishes later — must no-op, not re-drive the join or duplicate 'final'.
            $workflow->completeTask($this->instanceStepFor($instance, $branchB), 'done', null, $this->adminId());
            $this->assertSame(
                1,
                WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $join->id)->count()
            );
            $this->assertSame(
                1,
                WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('step_id', $final->id)->count()
            );
        });
    }

    /**
     * §3D's join predecessors are read from the graph (every step with a transition into the
     * join), not from which branch a conditional split actually took. A predecessor that's
     * declared but never reachable on the path actually taken must fail the instance instead
     * of leaving it silently stuck at `running` forever.
     */
    public function test_join_that_can_never_be_satisfied_fails_the_instance(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            [, $version] = $this->publishedDefinition('demo.unreachable_join');

            $entry = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_TASK, 'is_entry_step' => true]);
            $branchTaken = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_taken', 'type' => WrkflowStep::TYPE_TASK]);
            $branchNeverTaken = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'branch_never_taken', 'type' => WrkflowStep::TYPE_TASK]);
            $join = WrkflowStep::query()->create(['version_id' => $version->id, 'step_code' => 'join', 'type' => WrkflowStep::TYPE_PARALLEL_JOIN, 'config' => ['join_rule' => 'all']]);

            // entry always goes straight to branch_taken — branch_never_taken has no incoming
            // transition at all, so nothing ever begins it, yet it's still a declared
            // predecessor of the join.
            $entry->outgoingTransitions()->create(['to_step_id' => $branchTaken->id]);
            $branchTaken->outgoingTransitions()->create(['to_step_id' => $join->id]);
            $branchNeverTaken->outgoingTransitions()->create(['to_step_id' => $join->id]);

            $workflow = app(WorkflowService::class);
            $instance = $workflow->start('demo.unreachable_join', null, null, []);

            $workflow->completeTask($this->instanceStepFor($instance, $entry), 'done', null, $this->adminId());
            $workflow->completeTask($this->instanceStepFor($instance, $branchTaken), 'done', null, $this->adminId());

            $instance->refresh();
            $this->assertSame(WrkflowInstance::STATUS_FAILED, $instance->status);
            $this->assertNull($this->instanceStepFor($instance, $join));
        });
    }
}
