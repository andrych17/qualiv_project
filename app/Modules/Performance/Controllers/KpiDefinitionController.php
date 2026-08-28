<?php

namespace App\Modules\Performance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Perspective;
use App\Modules\Performance\Requests\StoreKpiDefinitionRequest;
use App\Modules\Performance\Requests\UpdateKpiDefinitionRequest;
use App\Modules\Performance\Services\KpiDefinitionService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3C KPI Definitions (Entry) — tenant-defined metric library, same pattern as CRM's partner_role_types. */
class KpiDefinitionController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['name', 'created_at'];

    public function __construct(protected KpiDefinitionService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'perspective_id', 'status', 'sort', 'direction', 'per_page');

        $kpis = KpiDefinition::query()
            ->with('perspective:id,name')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'name'),
                fn ($query) => $query->orderBy('name'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (KpiDefinition $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'unit' => $k->unit,
                'direction' => $k->direction,
                'perspective_name' => $k->perspective?->name,
                'is_active' => $k->is_active,
            ]);

        return Inertia::render('Performance/KpiDefinitions/Index', [
            'kpis' => $kpis,
            'filters' => $filters,
            'perspectives' => Perspective::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Performance/KpiDefinitions/Create', $this->formProps());
    }

    public function store(StoreKpiDefinitionRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('performance.kpiDefinitions.index')->with('success', 'KPI created.');
    }

    public function edit(KpiDefinition $kpiDefinition): Response
    {
        return Inertia::render('Performance/KpiDefinitions/Edit', [
            ...$this->formProps(),
            'kpi' => $kpiDefinition->only('id', 'name', 'unit', 'direction', 'perspective_id', 'description', 'is_active'),
        ]);
    }

    public function update(UpdateKpiDefinitionRequest $request, KpiDefinition $kpiDefinition)
    {
        $this->service->update($kpiDefinition, $request->validated());

        return redirect()->route('performance.kpiDefinitions.index')->with('success', 'KPI updated.');
    }

    public function destroy(KpiDefinition $kpiDefinition)
    {
        $this->service->delete($kpiDefinition);

        return redirect()->route('performance.kpiDefinitions.index')->with('success', 'KPI deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, KpiDefinition::class, fn (KpiDefinition $k) => $this->service->delete($k));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'perspectives' => Perspective::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
