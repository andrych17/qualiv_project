<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Events\OkrObjectiveCompleted;
use App\Modules\Performance\Models\OkrKeyResult;
use App\Modules\Performance\Models\OkrObjective;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3E — Objective CRUD plus its Key Results, managed inline (`syncLines`-style, same pattern as
 * Budget/Forecast lines) rather than as a separate CRUD surface, since a Key Result is never
 * referenced independently of its Objective.
 */
class OkrObjectiveService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): OkrObjective
    {
        $this->assertNoCycle(null, $data['parent_okr_id'] ?? null);

        $objective = DB::transaction(function () use ($data) {
            $objective = OkrObjective::query()->create([
                ...$this->headerAttributes($data),
                'status' => $data['status'] ?? OkrObjective::STATUS_ON_TRACK,
                'created_by' => auth()->id(),
            ]);
            $this->syncKeyResults($objective, $data['key_results'] ?? []);

            return $objective->load('keyResults');
        });

        // A brand-new objective can be created already 'completed' (e.g. backfilled data) —
        // treat that as a transition too, since there was no "previous" status to compare to.
        $this->dispatchIfCompleted($objective, previousStatus: null);

        return $objective;
    }

    /** @param  array<string, mixed>  $data */
    public function update(OkrObjective $objective, array $data): OkrObjective
    {
        $this->assertNoCycle($objective->id, $data['parent_okr_id'] ?? null);
        $previousStatus = $objective->status;

        $objective = DB::transaction(function () use ($objective, $data) {
            $objective->update([
                ...$this->headerAttributes($data),
                'status' => $data['status'] ?? $objective->status,
            ]);
            $this->syncKeyResults($objective, $data['key_results'] ?? []);

            return $objective->refresh()->load('keyResults');
        });

        $this->dispatchIfCompleted($objective, $previousStatus);

        return $objective;
    }

    public function updateStatus(OkrObjective $objective, string $status): OkrObjective
    {
        if (! in_array($status, OkrObjective::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid status.']);
        }

        $previousStatus = $objective->status;
        $objective->update(['status' => $status]);
        $objective->refresh();

        $this->dispatchIfCompleted($objective, $previousStatus);

        return $objective;
    }

    /**
     * §3I: fires only on the transition INTO 'completed' — $previousStatus MUST be captured
     * before the update() call lands, otherwise re-saving an already-completed objective would
     * re-dispatch on every edit (AchievementService's own dedup would mask it, but the event
     * would still be firing wrongly).
     */
    private function dispatchIfCompleted(OkrObjective $objective, ?string $previousStatus): void
    {
        if ($objective->status === OkrObjective::STATUS_COMPLETED && $previousStatus !== OkrObjective::STATUS_COMPLETED) {
            OkrObjectiveCompleted::dispatch($objective->id);
        }
    }

    public function delete(OkrObjective $objective): void
    {
        $objective->delete();
    }

    /**
     * Rejects a self-parent and walks the proposed parent's own ancestor chain — without this,
     * A→B, B→A (or any longer loop) would infinite-loop the indented alignment tree render.
     * `$objectiveId` is null on create, where a cycle is structurally impossible (nothing can
     * point at an id that doesn't exist yet).
     */
    private function assertNoCycle(?int $objectiveId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($objectiveId !== null && $parentId === $objectiveId) {
            throw ValidationException::withMessages(['parent_okr_id' => 'An objective cannot be its own parent.']);
        }

        $current = OkrObjective::query()->find($parentId);
        $depth = 0;

        while ($current !== null && $current->parent_okr_id !== null) {
            if ($objectiveId !== null && $current->parent_okr_id === $objectiveId) {
                throw ValidationException::withMessages(['parent_okr_id' => 'This would create a circular alignment chain.']);
            }

            $current = OkrObjective::query()->find($current->parent_okr_id);

            if (++$depth > 100) {
                break; // safety valve against already-corrupt data; not a normal path
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'cycle_id' => $data['cycle_id'],
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_type'] === OkrObjective::SUBJECT_COMPANY ? null : $data['subject_id'],
            'objective_text' => $data['objective_text'],
            'parent_okr_id' => $data['parent_okr_id'] ?? null,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $keyResults */
    private function syncKeyResults(OkrObjective $objective, array $keyResults): void
    {
        $objective->keyResults()->delete();

        foreach ($keyResults as $keyResult) {
            if (empty($keyResult['description']) || empty($keyResult['metric_type']) || ! isset($keyResult['target_value'])) {
                continue;
            }

            OkrKeyResult::query()->create([
                'okr_id' => $objective->id,
                'description' => $keyResult['description'],
                'metric_type' => $keyResult['metric_type'],
                'start_value' => $keyResult['start_value'] ?? 0,
                'current_value' => $keyResult['current_value'] ?? 0,
                'target_value' => $keyResult['target_value'],
                'weight' => $keyResult['weight'] ?? 100,
            ]);
        }
    }
}
