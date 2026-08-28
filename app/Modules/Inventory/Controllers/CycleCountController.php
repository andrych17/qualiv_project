<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\CycleCountLine;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreCycleCountRequest;
use App\Modules\Inventory\Services\CycleCountService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3Q Cycle Counting (Entry / Engine). */
class CycleCountController extends Controller
{
    private const SORTABLE = ['scheduled_date', 'created_at', 'completed_at'];

    public function __construct(protected CycleCountService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('warehouse_id', 'status', 'sort', 'direction', 'per_page');

        $counts = CycleCount::query()
            ->with(['warehouse:id,name', 'location:id,code', 'category:id,name', 'assignedTo:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->where('status', CycleCountLine::STATUS_COUNTED)])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (CycleCount $c) => [
                'id' => $c->id,
                'warehouse_name' => $c->warehouse?->name,
                'scope' => $c->location ? "Location {$c->location->code}" : ($c->category ? "Category {$c->category->name}" : ($c->abc_class ? "ABC class {$c->abc_class}" : '—')),
                'assigned_to_name' => $c->assignedTo?->name,
                'status' => $c->status,
                'line_count' => $c->lines_count,
                'counted_lines_count' => $c->counted_lines_count,
                'scheduled_date_formatted' => $c->scheduled_date?->format('d M Y'),
            ]);

        return Inertia::render('Inventory/CycleCounts/Index', [
            'counts' => $counts,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/CycleCounts/Create', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreCycleCountRequest $request)
    {
        $count = $this->service->create($request->validated());

        return redirect()->route('inventory.cycleCounts.show', $count)->with('success', 'Cycle count generated.');
    }

    public function show(CycleCount $count): Response
    {
        $lines = $count->lines()
            ->with(['product:id,sku,name', 'batch:id,batch_number', 'location:id,code'])
            ->get()
            ->sortBy(fn (CycleCountLine $l) => $l->location?->code);

        return Inertia::render('Inventory/CycleCounts/Show', [
            'count' => [
                'id' => $count->id,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_name' => $count->warehouse?->name,
                'scope' => $count->location ? "Location {$count->location->code}" : ($count->category ? "Category {$count->category->name}" : "ABC class {$count->abc_class}"),
                'status' => $count->status,
                'assigned_to' => $count->assigned_to,
                'scheduled_date_formatted' => $count->scheduled_date?->format('d M Y'),
                'completed_at_formatted' => $count->completed_at?->format('d M Y H:i'),
            ],
            'lines' => $lines->map(fn (CycleCountLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'product_sku' => $l->product?->sku,
                'product_name' => $l->product?->name,
                'location_id' => $l->location_id,
                'location_code' => $l->location?->code,
                'batch_number' => $l->batch?->batch_number,
                'system_qty' => $l->system_qty !== null ? (float) $l->system_qty : null,
                'counted_qty' => $l->counted_qty !== null ? (float) $l->counted_qty : null,
                'status' => $l->status,
                'counted_at_formatted' => $l->counted_at?->format('d M Y H:i'),
            ]),
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assign(Request $request, CycleCount $count)
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer']);

        $this->service->assign($count, $data['assigned_to'] ?? null);

        return back()->with('success', 'Cycle count assignment updated.');
    }

    public function countLine(Request $request, CycleCount $count, CycleCountLine $line)
    {
        $data = $request->validate(['counted_qty' => 'required|numeric|min:0']);

        $this->service->countLine($line, (float) $data['counted_qty']);

        return back()->with('success', 'Line counted.');
    }

    public function complete(CycleCount $count)
    {
        $result = $this->service->complete($count);

        $ids = $result['adjustments']->pluck('id')->map(fn ($id) => "#{$id}")->implode(', ');

        return redirect()->route('inventory.cycleCounts.show', $count)->with('success', "Count completed — drafted adjustment(s) {$ids} for review.");
    }

    public function destroy(CycleCount $count)
    {
        $this->service->delete($count);

        return redirect()->route('inventory.cycleCounts.index')->with('success', 'Cycle count deleted.');
    }
}
