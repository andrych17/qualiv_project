<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MES_SPECS.md §3F — a recipe's whole phase set (+ nested parameters per phase). No MES-owned
 * header row exists (the recipe header lives in PP.pp_recipes, §3B) — `recipe_id` is the
 * grouping key, and every save replaces the full set for that recipe, same replace-all
 * discipline as PP's BomService::syncLines() one level deeper.
 */
class ProcessPhaseService
{
    public function __construct(protected MesAuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, ProcessPhase>
     */
    public function create(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $this->syncPhases((int) $data['recipe_id'], $data['phases'] ?? []);

            return $this->phasesFor((int) $data['recipe_id']);
        });
    }

    /**
     * §3U example: "a process-parameter target changed on an active recipe" — logged as one
     * before/after snapshot of the whole phase set per update, same replace-all granularity as
     * the update itself (not a per-parameter diff).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, ProcessPhase>
     */
    public function update(int $recipeId, array $data, int $userId): Collection
    {
        return DB::transaction(function () use ($recipeId, $data, $userId) {
            $before = $this->snapshotFor($recipeId);

            $this->syncPhases($recipeId, $data['phases'] ?? []);

            $after = $this->snapshotFor($recipeId);
            $this->audit->log('mes.mes_process_phases', $recipeId, 'updated', $before, $after, $userId);

            return $this->phasesFor($recipeId);
        });
    }

    public function delete(int $recipeId): void
    {
        DB::transaction(function () use ($recipeId) {
            ProcessPhase::query()->where('recipe_id', $recipeId)->delete();
        });
    }

    /** @return Collection<int, ProcessPhase> */
    public function phasesFor(int $recipeId)
    {
        return ProcessPhase::query()
            ->where('recipe_id', $recipeId)
            ->orderBy('seq')
            ->with('parameters')
            ->get();
    }

    /** @return list<array<string, mixed>> */
    private function snapshotFor(int $recipeId): array
    {
        return $this->phasesFor($recipeId)->map(fn (ProcessPhase $phase) => [
            'phase_name' => $phase->phase_name,
            'seq' => $phase->seq,
            'standard_duration_minutes' => $phase->standard_duration_minutes,
            'parameters' => $phase->parameters->map(fn (ProcessParameter $p) => [
                'parameter_code' => $p->parameter_code,
                'target_value' => $p->target_value !== null ? (float) $p->target_value : null,
                'min_value' => $p->min_value !== null ? (float) $p->min_value : null,
                'max_value' => $p->max_value !== null ? (float) $p->max_value : null,
            ])->all(),
        ])->all();
    }

    /** @param  list<array<string, mixed>>  $phases */
    private function syncPhases(int $recipeId, array $phases): void
    {
        ProcessPhase::query()->where('recipe_id', $recipeId)->delete();

        foreach ($phases as $seq => $phase) {
            if (empty($phase['phase_name'])) {
                continue;
            }

            $processPhase = ProcessPhase::query()->create([
                'recipe_id' => $recipeId,
                'seq' => ($seq + 1) * 10,
                'phase_name' => $phase['phase_name'],
                'work_center_id' => $phase['work_center_id'] ?? null,
                'standard_duration_minutes' => $phase['standard_duration_minutes'] ?? null,
            ]);

            foreach ((array) ($phase['parameters'] ?? []) as $parameter) {
                if (empty($parameter['parameter_code'])) {
                    continue;
                }

                ProcessParameter::query()->create([
                    'process_phase_id' => $processPhase->id,
                    'parameter_code' => $parameter['parameter_code'],
                    'target_value' => $parameter['target_value'] ?? null,
                    'min_value' => $parameter['min_value'] ?? null,
                    'max_value' => $parameter['max_value'] ?? null,
                    'uom_code' => $parameter['uom_code'] ?? null,
                ]);
            }
        }
    }
}
