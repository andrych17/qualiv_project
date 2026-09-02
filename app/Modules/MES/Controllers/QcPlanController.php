<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\QcInspectionPlan;
use App\Modules\MES\Requests\StoreQcPlanRequest;
use App\Modules\MES\Requests\UpdateQcPlanRequest;
use App\Modules\MES\Services\QcPlanService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3L QC Inspection Plans (Entry) — plan header + characteristics, same replace-all-lines shape as Routing/Process Phases. */
class QcPlanController extends Controller
{
    private const SORTABLE = ['name'];

    public function __construct(protected QcPlanService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $plans = QcInspectionPlan::query()
            ->with('product:id,sku,name')
            ->withCount('characteristics')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (QcInspectionPlan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'product_sku' => $p->product?->sku,
                'product_name' => $p->product?->name,
                'characteristic_count' => $p->characteristics_count,
            ]);

        return Inertia::render('MES/QcPlans/Index', [
            'plans' => $plans,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/QcPlans/Create');
    }

    public function store(StoreQcPlanRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.qcPlans.index')->with('success', 'QC inspection plan created.');
    }

    public function edit(QcInspectionPlan $qcPlan): Response
    {
        return Inertia::render('MES/QcPlans/Edit', [
            'plan' => $this->toFormData($qcPlan),
        ]);
    }

    public function update(UpdateQcPlanRequest $request, QcInspectionPlan $qcPlan)
    {
        $this->service->update($qcPlan, $request->validated());

        return redirect()->route('mes.qcPlans.index')->with('success', 'QC inspection plan updated.');
    }

    public function destroy(QcInspectionPlan $qcPlan)
    {
        $this->service->delete($qcPlan);

        return redirect()->route('mes.qcPlans.index')->with('success', 'QC inspection plan deleted.');
    }

    /** @return array<string, mixed> */
    private function toFormData(QcInspectionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'product_id' => $plan->product_id,
            'product_label' => $plan->product ? "{$plan->product->sku} — {$plan->product->name}" : null,
            'name' => $plan->name,
            'characteristics' => $plan->characteristics()->get()->map(fn ($c) => [
                'characteristic_name' => $c->characteristic_name,
                'spec_type' => $c->spec_type,
                'target_value' => $c->target_value !== null ? (float) $c->target_value : null,
                'min_value' => $c->min_value !== null ? (float) $c->min_value : null,
                'max_value' => $c->max_value !== null ? (float) $c->max_value : null,
                'uom_code' => $c->uom_code,
            ]),
        ];
    }
}
