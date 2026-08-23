<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Requests\StoreCostCenterRequest;
use App\Modules\Accounting\Requests\UpdateCostCenterRequest;
use App\Modules\Accounting\Services\CostCenterService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** §3B/§3I cost center dimension — depth-indented flat listing, same convention as Accounts/Folders. */
class CostCenterController extends Controller
{
    public function __construct(private readonly CostCenterService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);
        $companyId = (int) ($request->integer('company_id') ?: $companies->first()?->id);

        $costCenters = CostCenter::query()->where('company_id', $companyId)->orderBy('code')->get();

        return Inertia::render('Accounting/CostCenters/Index', [
            'companies' => $companies,
            'selectedCompanyId' => $companyId,
            'costCenters' => $this->indent($costCenters)->map(fn (CostCenter $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'depth' => $c->depth,
                'is_active' => $c->is_active,
            ])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = (int) $request->integer('company_id');

        return Inertia::render('Accounting/CostCenters/Create', [
            'companies' => Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']),
            'selectedCompanyId' => $companyId ?: null,
            'parents' => $this->parentOptions($companyId),
        ]);
    }

    public function store(StoreCostCenterRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('accounting.cost-centers.index', ['company_id' => $request->input('company_id')])
            ->with('success', 'Cost center created.');
    }

    public function edit(CostCenter $costCenter): Response
    {
        return Inertia::render('Accounting/CostCenters/Edit', [
            'costCenter' => $costCenter->only(['id', 'company_id', 'code', 'name', 'parent_cost_center_id', 'is_active']),
            'parents' => $this->parentOptions($costCenter->company_id, $costCenter->id),
        ]);
    }

    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter)
    {
        $this->service->update($costCenter, $request->validated());

        return redirect()->route('accounting.cost-centers.index', ['company_id' => $costCenter->company_id])
            ->with('success', 'Cost center updated.');
    }

    public function destroy(CostCenter $costCenter)
    {
        $companyId = $costCenter->company_id;
        $this->service->delete($costCenter);

        return redirect()->route('accounting.cost-centers.index', ['company_id' => $companyId])
            ->with('success', 'Cost center deleted.');
    }

    private function indent(Collection $costCenters): Collection
    {
        $byParent = $costCenters->groupBy('parent_cost_center_id');
        $ordered = collect();

        $walk = function (?int $parentId, int $depth) use (&$walk, &$ordered, $byParent) {
            foreach ($byParent->get($parentId) ?? [] as $costCenter) {
                $costCenter->depth = $depth;
                $ordered->push($costCenter);
                $walk($costCenter->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $ordered;
    }

    private function parentOptions(?int $companyId, ?int $excludeId = null): array
    {
        if (! $companyId) {
            return [];
        }

        return CostCenter::query()
            ->where('company_id', $companyId)
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (CostCenter $c) => ['value' => $c->id, 'label' => "{$c->code} {$c->name}"])
            ->values()
            ->all();
    }
}
