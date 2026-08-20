<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowTransition;
use App\Modules\WNE\Models\WrkflowVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3B — author a process without writing code. Owns the definition header and
 * the step/transition graph on the current draft version; §3C's WorkflowService
 * owns everything about running instances and never mutates this side.
 */
class WorkflowDefinitionService
{
    public function create(array $data, ?int $actorId): WrkflowDefinition
    {
        return WrkflowDefinition::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'status' => WrkflowDefinition::STATUS_DRAFT,
            'created_by' => $actorId,
        ]);
    }

    public function updateHeader(WrkflowDefinition $definition, array $data): WrkflowDefinition
    {
        $definition->update(Arr::only($data, ['name', 'description', 'category_id']));

        return $definition;
    }

    /**
     * Get-or-create the version this request should edit (§3B rule: "editing a
     * published definition always edits a new draft, never the published
     * version in place"). Seeded as a copy of the last published version's
     * steps/transitions so editing continues from where publish() left off,
     * rather than forcing the author to rebuild the graph from scratch.
     */
    public function draftVersion(WrkflowDefinition $definition): WrkflowVersion
    {
        $draft = $definition->draftVersion();

        if ($draft) {
            return $draft;
        }

        return DB::transaction(function () use ($definition) {
            $nextVersionNo = ($definition->versions()->max('version_no') ?? 0) + 1;
            $draft = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => $nextVersionNo]);

            $source = $definition->lastPublishedVersion();
            if ($source) {
                $this->copyStepsAndTransitions($source, $draft);
            }

            return $draft;
        });
    }

    private function copyStepsAndTransitions(WrkflowVersion $source, WrkflowVersion $target): void
    {
        $stepIdMap = [];

        foreach ($source->steps()->get() as $step) {
            $copy = WrkflowStep::query()->create([
                'version_id' => $target->id,
                'step_code' => $step->step_code,
                'type' => $step->type,
                'config' => $step->config,
                'pos_x' => $step->pos_x,
                'pos_y' => $step->pos_y,
                'is_entry_step' => $step->is_entry_step,
            ]);
            $stepIdMap[$step->id] = $copy->id;
        }

        $transitions = WrkflowTransition::query()->whereIn('from_step_id', array_keys($stepIdMap))->orderBy('seq')->get();

        foreach ($transitions as $transition) {
            WrkflowTransition::query()->create([
                'from_step_id' => $stepIdMap[$transition->from_step_id],
                'to_step_id' => $stepIdMap[$transition->to_step_id],
                'condition_expression' => $transition->condition_expression,
                'seq' => $transition->seq,
            ]);
        }
    }

    public function addStep(WrkflowDefinition $definition, array $data): WrkflowStep
    {
        $draft = $this->draftVersion($definition);

        return DB::transaction(function () use ($draft, $data) {
            if ($data['is_entry_step'] ?? false) {
                $draft->steps()->where('is_entry_step', true)->update(['is_entry_step' => false]);
            }

            return $draft->steps()->create([
                'step_code' => $data['step_code'],
                'type' => $data['type'],
                'config' => $data['config'] ?? [],
                'pos_x' => $data['pos_x'] ?? null,
                'pos_y' => $data['pos_y'] ?? null,
                'is_entry_step' => $data['is_entry_step'] ?? false,
            ]);
        });
    }

    public function updateStep(WrkflowStep $step, array $data): WrkflowStep
    {
        $this->assertEditable($step->version, 'step');

        return DB::transaction(function () use ($step, $data) {
            if (($data['is_entry_step'] ?? false) && ! $step->is_entry_step) {
                WrkflowStep::query()->where('version_id', $step->version_id)->where('id', '!=', $step->id)
                    ->update(['is_entry_step' => false]);
            }

            $step->update(Arr::only($data, ['step_code', 'type', 'config', 'pos_x', 'pos_y', 'is_entry_step']));

            return $step;
        });
    }

    public function deleteStep(WrkflowStep $step): void
    {
        $this->assertEditable($step->version, 'step');

        DB::transaction(function () use ($step) {
            // to_step_id has no cascade (§3C: a published step's history must survive its
            // "from" side being deleted) — but this step is still an unpublished draft, so
            // detaching incoming transitions here is safe and keeps the graph consistent.
            WrkflowTransition::query()->where('to_step_id', $step->id)->delete();
            $step->delete();
        });
    }

    public function addTransition(WrkflowDefinition $definition, array $data): WrkflowTransition
    {
        $draft = $this->draftVersion($definition);

        $fromStep = WrkflowStep::query()->findOrFail($data['from_step_id']);
        $toStep = WrkflowStep::query()->findOrFail($data['to_step_id']);

        if ($fromStep->version_id !== $draft->id || $toStep->version_id !== $draft->id) {
            throw ValidationException::withMessages(['to_step_id' => 'Both steps must belong to the workflow being edited.']);
        }

        if ($fromStep->id === $toStep->id) {
            throw ValidationException::withMessages(['to_step_id' => 'A step cannot transition to itself.']);
        }

        return WrkflowTransition::query()->create([
            'from_step_id' => $fromStep->id,
            'to_step_id' => $toStep->id,
            'condition_expression' => $data['condition_expression'] ?? null,
            'seq' => $data['seq'] ?? 0,
        ]);
    }

    public function deleteTransition(WrkflowTransition $transition): void
    {
        $this->assertEditable($transition->fromStep->version, 'transition');

        $transition->delete();
    }

    /**
     * Validates the graph (§3B: every step reachable from the entry step —
     * which also rules out orphans — exactly one entry step, every
     * parallel_split paired with at least one parallel_join) and snapshots
     * it as published: sets the draft's real published_at/published_by
     * (its DB-default published_at from row-creation was never the true
     * publish moment) and flips the definition to `published`.
     */
    public function publish(WrkflowDefinition $definition, int $actorId): WrkflowDefinition
    {
        $draft = $definition->draftVersion();

        if (! $draft) {
            throw ValidationException::withMessages(['definition' => 'There are no unpublished changes to publish.']);
        }

        $steps = $draft->steps()->get();
        $transitions = WrkflowTransition::query()->whereIn('from_step_id', $steps->pluck('id'))->get();

        $this->validateGraph($steps, $transitions);

        DB::transaction(function () use ($draft, $definition, $actorId) {
            $draft->update(['published_at' => now(), 'published_by' => $actorId]);
            $definition->update(['status' => WrkflowDefinition::STATUS_PUBLISHED]);
        });

        return $definition->refresh();
    }

    /** @param  Collection<int, WrkflowStep>  $steps
     * @param  Collection<int, WrkflowTransition>  $transitions */
    private function validateGraph($steps, $transitions): void
    {
        $entrySteps = $steps->where('is_entry_step', true);

        if ($entrySteps->count() !== 1) {
            throw ValidationException::withMessages(['steps' => 'A workflow needs exactly one entry step.']);
        }

        $adjacency = [];
        foreach ($transitions as $transition) {
            $adjacency[$transition->from_step_id][] = $transition->to_step_id;
        }

        $entryId = $entrySteps->first()->id;
        $visited = [$entryId => true];
        $queue = [$entryId];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $nextId) {
                if (! isset($visited[$nextId])) {
                    $visited[$nextId] = true;
                    $queue[] = $nextId;
                }
            }
        }

        $orphans = $steps->reject(fn (WrkflowStep $step) => isset($visited[$step->id]))->pluck('step_code');
        if ($orphans->isNotEmpty()) {
            throw ValidationException::withMessages(['steps' => 'Unreachable from the entry step: '.$orphans->implode(', ').'.']);
        }

        $splitCount = $steps->where('type', WrkflowStep::TYPE_PARALLEL_SPLIT)->count();
        $joinCount = $steps->where('type', WrkflowStep::TYPE_PARALLEL_JOIN)->count();
        if ($splitCount > 0 && $joinCount === 0) {
            throw ValidationException::withMessages(['steps' => 'Every parallel_split step needs a matching parallel_join step.']);
        }
    }

    public function unpublish(WrkflowDefinition $definition): WrkflowDefinition
    {
        if ($definition->status !== WrkflowDefinition::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['status' => 'Only a published workflow can be unpublished.']);
        }

        $definition->update(['status' => WrkflowDefinition::STATUS_UNPUBLISHED]);

        return $definition;
    }

    /** Only a definition that has never been published can be deleted outright — once published, its version history is instance-pinned (§3E), so unpublish() is the only reversal offered. */
    public function delete(WrkflowDefinition $definition): void
    {
        if ($definition->lastPublishedVersion() !== null) {
            throw ValidationException::withMessages(['definition' => 'A workflow that has ever been published cannot be deleted — unpublish it instead.']);
        }

        DB::transaction(function () use ($definition) {
            $versionIds = $definition->versions()->pluck('id');
            $stepIds = WrkflowStep::query()->whereIn('version_id', $versionIds)->pluck('id');
            WrkflowTransition::query()->whereIn('from_step_id', $stepIds)->orWhereIn('to_step_id', $stepIds)->delete();
            $definition->delete();
        });
    }

    /**
     * §3F: attach/replace the SLA rule for one step. Step-level rules always win over a
     * version-level default (WrkflowSlaRule::resolveFor()) — draft-only, same as every other
     * graph edit, so an already-published version's SLA behavior can never change out from
     * under an instance running on it (§3E).
     */
    public function setStepSlaRule(WrkflowStep $step, float $slaHours, string $escalationAction, ?string $escalationTarget): WrkflowSlaRule
    {
        $this->assertEditable($step->version, 'sla_hours');
        $this->assertValidEscalationAction($escalationAction);

        return WrkflowSlaRule::query()->updateOrCreate(
            ['step_id' => $step->id],
            ['version_id' => null, 'sla_hours' => $slaHours, 'escalation_action' => $escalationAction, 'escalation_target' => $escalationTarget]
        );
    }

    public function removeStepSlaRule(WrkflowStep $step): void
    {
        $this->assertEditable($step->version, 'sla_hours');

        WrkflowSlaRule::query()->where('step_id', $step->id)->delete();
    }

    /** §3F: a version-level default applied to any human step in this version with no step-specific rule of its own. */
    public function setVersionDefaultSlaRule(WrkflowVersion $version, float $slaHours, string $escalationAction, ?string $escalationTarget): WrkflowSlaRule
    {
        $this->assertEditable($version, 'sla_hours');
        $this->assertValidEscalationAction($escalationAction);

        return WrkflowSlaRule::query()->updateOrCreate(
            ['version_id' => $version->id, 'step_id' => null],
            ['sla_hours' => $slaHours, 'escalation_action' => $escalationAction, 'escalation_target' => $escalationTarget]
        );
    }

    private function assertValidEscalationAction(string $escalationAction): void
    {
        if (! in_array($escalationAction, WrkflowSlaRule::ACTIONS, true)) {
            throw ValidationException::withMessages(['escalation_action' => 'Unknown escalation action: '.$escalationAction]);
        }
    }

    private function assertEditable(WrkflowVersion $version, string $field): void
    {
        if ($version->published_by !== null) {
            throw ValidationException::withMessages([$field => 'This version has already been published and cannot be edited — make a change to start a new draft.']);
        }
    }
}
