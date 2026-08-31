<?php

namespace App\Modules\PP\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Requests\StoreCapacityPlanRequest;
use App\Modules\PP\Requests\UpdateCapacityPlanRequest;
use App\Modules\PP\Services\CapacityPlanService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PP_SPECS.md §3F Capacity Planning — RCCP (Entry). Rough-cut only: informational load vs. available, not finite enforcement (that's §3H). */
class CapacityPlanController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['period_start', 'required_hours', 'available_hours', 'created_at'];

    public function __construct(
        protected CapacityPlanService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'period_start', 'sort', 'direction', 'per_page');

        $plans = CapacityPlan::query()
            ->baseline()
            ->with(['resourceGroup:id,code,name', 'ppResource:id,code,name'])
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'period_start'),
                fn ($query) => $query->orderBy('period_start'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (CapacityPlan $p) => [
                'id' => $p->id,
                'target_label' => $this->targetLabel($p),
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'required_hours' => (float) $p->required_hours,
                'available_hours' => (float) $p->available_hours,
                'load_pct' => $this->service->loadPct($p),
                'is_overloaded' => $this->service->isOverloaded($p),
            ]);

        return Inertia::render('PP/CapacityPlans/Index', [
            'plans' => $plans,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PP/CapacityPlans/Create', [
            'resourceGroupOptions' => $this->resourceGroupOptions(),
            'resourceOptions' => $this->resourceOptions(),
        ]);
    }

    public function store(StoreCapacityPlanRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('pp.capacityPlans.index')->with('success', 'Capacity plan created.');
    }

    public function edit(CapacityPlan $capacity_plan): Response
    {
        return Inertia::render('PP/CapacityPlans/Edit', [
            'plan' => $this->toFormData($capacity_plan),
            'resourceGroupOptions' => $this->resourceGroupOptions(),
            'resourceOptions' => $this->resourceOptions(),
        ]);
    }

    public function update(UpdateCapacityPlanRequest $request, CapacityPlan $capacity_plan)
    {
        $this->service->update($capacity_plan, $request->validated());

        return redirect()->route('pp.capacityPlans.index')->with('success', 'Capacity plan updated.');
    }

    public function destroy(CapacityPlan $capacity_plan)
    {
        $this->service->delete($capacity_plan);

        return redirect()->route('pp.capacityPlans.index')->with('success', 'Capacity plan deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, CapacityPlan::class, fn (CapacityPlan $p) => $this->service->delete($p));
    }

    private function targetLabel(CapacityPlan $plan): string
    {
        if ($plan->resource_group_id) {
            return $plan->resourceGroup ? "Group: {$plan->resourceGroup->code}" : 'Group: (deleted)';
        }

        if ($plan->resource_type === CapacityPlan::RESOURCE_TYPE_PP_RESOURCE) {
            return $plan->ppResource ? $plan->ppResource->code : 'Resource: (deleted)';
        }

        return strtoupper(str_replace('_', ' ', (string) $plan->resource_type))." #{$plan->resource_ref_id} (informational)";
    }

    /** @return array<string, mixed> */
    private function toFormData(CapacityPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'resource_group_id' => $plan->resource_group_id,
            'resource_type' => $plan->resource_type,
            'resource_ref_id' => $plan->resource_ref_id,
            'period_start' => $plan->period_start->toDateString(),
            'period_end' => $plan->period_end->toDateString(),
            'required_hours' => (float) $plan->required_hours,
            'available_hours' => (float) $plan->available_hours,
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function resourceGroupOptions(): array
    {
        return ResourceGroup::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (ResourceGroup $g) => ['value' => $g->id, 'label' => "{$g->code} — {$g->name}"])
            ->all();
    }

    /** @return list<array{value: int, label: string}> */
    private function resourceOptions(): array
    {
        return Resource::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Resource $r) => ['value' => $r->id, 'label' => "{$r->code} — {$r->name}"])
            ->all();
    }
}
