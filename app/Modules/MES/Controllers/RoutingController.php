<?php

namespace App\Modules\MES\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MES\Models\Routing;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Requests\StoreRoutingRequest;
use App\Modules\MES\Requests\UpdateRoutingRequest;
use App\Modules\MES\Services\RoutingService;
use App\Shared\Helpers\TableQuery;
use App\Shared\Traits\BulkDeletable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** MES_SPECS.md §3E Routing / Operations (Entry) — discrete execution-step sequence. */
class RoutingController extends Controller
{
    use BulkDeletable;

    private const SORTABLE = ['version', 'created_at'];

    public function __construct(protected RoutingService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'sort', 'direction', 'per_page');

        $routings = Routing::query()
            ->with('product:id,sku,name')
            ->withCount('ops')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Routing $r) => [
                'id' => $r->id,
                'product_sku' => $r->product?->sku,
                'product_name' => $r->product?->name,
                'version' => $r->version,
                'op_count' => $r->ops_count,
                'is_active' => $r->is_active,
            ]);

        return Inertia::render('MES/Routings/Index', [
            'routings' => $routings,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MES/Routings/Create', [
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function store(StoreRoutingRequest $request)
    {
        $this->service->create($request->validated());

        return redirect()->route('mes.routings.index')->with('success', 'Routing created.');
    }

    public function edit(Routing $routing): Response
    {
        return Inertia::render('MES/Routings/Edit', [
            'routing' => $this->toFormData($routing),
            'workCenters' => $this->workCenterOptions(),
        ]);
    }

    public function update(UpdateRoutingRequest $request, Routing $routing)
    {
        $this->service->update($routing, $request->validated());

        return redirect()->route('mes.routings.index')->with('success', 'Routing updated.');
    }

    public function destroy(Routing $routing)
    {
        $this->service->delete($routing);

        return redirect()->route('mes.routings.index')->with('success', 'Routing deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        return $this->bulkDestroyUsing($request, Routing::class, fn (Routing $r) => $this->service->delete($r));
    }

    /** @return array<string, mixed> */
    private function toFormData(Routing $routing): array
    {
        return [
            'id' => $routing->id,
            'product_id' => $routing->product_id,
            'product_label' => $routing->product ? "{$routing->product->sku} — {$routing->product->name}" : null,
            'version' => $routing->version,
            'is_active' => $routing->is_active,
            'ops' => $routing->ops()->orderBy('seq')->get()->map(fn ($op) => [
                'op_code' => $op->op_code,
                'op_name' => $op->op_name,
                'work_center_id' => $op->work_center_id,
                'setup_time_minutes' => $op->setup_time_minutes,
                'run_time_minutes' => $op->run_time_minutes,
                'queue_time_minutes' => $op->queue_time_minutes,
                'standard_output_qty' => $op->standard_output_qty !== null ? (float) $op->standard_output_qty : null,
                'instructions' => $op->instructions,
                'auto_issue_components' => $op->auto_issue_components,
                'is_rework_destination' => $op->is_rework_destination,
            ]),
        ];
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
