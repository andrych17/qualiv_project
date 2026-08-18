<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralTenantAddon;
use App\Modules\Central\Requests\ReactivateTenantRequest;
use App\Modules\Central\Requests\StoreTenantRequest;
use App\Modules\Central\Requests\UpdateTenantRequest;
use App\Modules\Central\Services\CentralTenantService;
use App\Modules\Central\Support\ModuleCatalog;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    private const SORTABLE = ['id', 'name', 'plan', 'access_status'];

    public function __construct(
        protected CentralTenantService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $tenants = Tenant::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'ilike', "%{$search}%"))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id'),
                fn ($query) => $query->orderBy('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString();

        return Inertia::render('Central/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Central/Tenants/Create', [
            'plans' => $this->planOptions(),
        ]);
    }

    public function store(StoreTenantRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('central.tenants.index')->with('success', 'Tenant registered and provisioned.');
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Central/Tenants/Edit', [
            'tenant' => $tenant,
            'plans' => $this->planOptions(),
            'addons' => CentralTenantAddon::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('status', 'active')
                ->orderBy('module_code')
                ->get(['id', 'module_code', 'price_override', 'added_at']),
            'availableModules' => ModuleCatalog::codes(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $this->service->update($tenant, $request->validated());

        return redirect()->route('central.tenants.index')->with('success', 'Tenant updated.');
    }

    /** Exceptional manual override alongside dunning's automatic reactivate-on-payment (§3G). */
    public function reactivate(ReactivateTenantRequest $request, Tenant $tenant)
    {
        $this->service->reactivate($tenant, $request->validated('reason'));

        return redirect()->back()->with('success', 'Tenant reactivated.');
    }

    private function planOptions(): array
    {
        return CentralPlan::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (CentralPlan $plan) => ['label' => "{$plan->name} ({$plan->code})", 'value' => $plan->code])
            ->values()
            ->all();
    }
}
