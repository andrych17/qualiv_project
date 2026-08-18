<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Requests\StorePlanRequest;
use App\Modules\Central\Requests\UpdatePlanRequest;
use App\Modules\Central\Services\CentralPlanService;
use App\Modules\Central\Support\ModuleCatalog;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    private const SORTABLE = ['code', 'name', 'price_monthly', 'is_active'];

    public function __construct(
        protected CentralPlanService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $plans = CentralPlan::query()
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'code'),
                fn ($query) => $query->orderBy('code'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('Central/Plans/Index', [
            'plans' => $plans,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Central/Plans/Create', [
            'availableModules' => ModuleCatalog::codes(),
        ]);
    }

    public function store(StorePlanRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('central.plans.index')->with('success', 'Plan created.');
    }

    public function edit(CentralPlan $plan): Response
    {
        return Inertia::render('Central/Plans/Edit', [
            'plan' => [
                ...$plan->toArray(),
                'module_codes' => $plan->modules()->pluck('module_code')->values(),
            ],
            'availableModules' => ModuleCatalog::codes(),
        ]);
    }

    public function update(UpdatePlanRequest $request, CentralPlan $plan)
    {
        $this->service->update($plan, $request->validated());

        return redirect()->route('central.plans.index')->with('success', 'Plan updated.');
    }

    public function destroy(CentralPlan $plan)
    {
        $this->service->deactivate($plan);

        return redirect()->route('central.plans.index')->with('success', 'Plan deactivated.');
    }
}
