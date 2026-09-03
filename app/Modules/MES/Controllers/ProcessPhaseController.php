<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreProcessPhaseSetRequest;
use App\Modules\MES\Requests\UpdateProcessPhaseSetRequest;
use App\Modules\MES\Services\ProcessPhaseService;
use App\Modules\PP\Models\Recipe;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MES_SPECS.md §3F Process Phases & Parameters (Entry) — one recipe's whole phase set at a
 * time, since MES owns no header row of its own for this section (the header is PP's
 * `pp_recipes`, §3B boundary note). `{recipe}` route-model-binds against `PP.pp_recipes.id`.
 */
class ProcessPhaseController extends Controller
{
    private const SORTABLE = ['recipe_id'];

    public function __construct(protected ProcessPhaseService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $groups = ProcessPhase::query()
            ->selectRaw('recipe_id, COUNT(*) as phase_count')
            ->groupBy('recipe_id')
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'recipe_id'),
                fn ($query) => $query->orderBy('recipe_id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        $recipes = Recipe::query()
            ->with('product:id,sku,name')
            ->whereIn('id', collect($groups->items())->pluck('recipe_id'))
            ->get()
            ->keyBy('id');

        $groups->through(fn ($row) => [
            'recipe_id' => $row->recipe_id,
            'product_sku' => $recipes->get($row->recipe_id)?->product?->sku,
            'product_name' => $recipes->get($row->recipe_id)?->product?->name,
            'version' => $recipes->get($row->recipe_id)?->version,
            'phase_count' => $row->phase_count,
        ]);

        return Inertia::render('MES/ProcessPhases/Index', [
            'phaseSets' => $groups,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/ProcessPhases/Create', [
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function store(StoreProcessPhaseSetRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.processPhases.index')->with('success', 'Process phases created.');
    }

    public function edit(Recipe $recipe): Response
    {
        $recipe->loadMissing('product:id,sku,name');

        return Inertia::render('MES/ProcessPhases/Edit', [
            'recipe' => [
                'id' => $recipe->id,
                'label' => $recipe->product ? "{$recipe->product->sku} — {$recipe->product->name} (v{$recipe->version})" : "Recipe #{$recipe->id}",
            ],
            'phases' => $this->service->phasesFor($recipe->id)->map(fn (ProcessPhase $phase) => [
                'phase_name' => $phase->phase_name,
                'work_center_id' => $phase->work_center_id,
                'standard_duration_minutes' => $phase->standard_duration_minutes,
                'parameters' => $phase->parameters->map(fn ($p) => [
                    'parameter_code' => $p->parameter_code,
                    'target_value' => $p->target_value !== null ? (float) $p->target_value : null,
                    'min_value' => $p->min_value !== null ? (float) $p->min_value : null,
                    'max_value' => $p->max_value !== null ? (float) $p->max_value : null,
                    'uom_code' => $p->uom_code,
                ]),
            ]),
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function update(UpdateProcessPhaseSetRequest $request, Recipe $recipe)
    {
        $this->service->update($recipe->id, $request->validated(), $request->user()->id);

        return redirect()->route('mes.processPhases.index')->with('success', 'Process phases updated.');
    }

    public function destroy(Recipe $recipe)
    {
        $this->service->delete($recipe->id);

        return redirect()->route('mes.processPhases.index')->with('success', 'Process phases deleted.');
    }

    /** @return list<array{value: int, label: string}> */
    private function workCenterOptions(): array
    {
        return WorkCenter::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (WorkCenter $w) => ['value' => $w->id, 'label' => "{$w->code} — {$w->name}"])
            ->all();
    }
}
