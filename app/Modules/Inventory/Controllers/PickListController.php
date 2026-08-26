<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\PickListService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3O Picking (Entry / Engine). No manual create — see PickListService::generate() and ReservationController::generatePickList(). */
class PickListController extends Controller
{
    private const SORTABLE = ['created_at', 'completed_at'];

    public function __construct(protected PickListService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('warehouse_id', 'status', 'sort', 'direction', 'per_page');

        $pickLists = PickList::query()
            ->with(['warehouse:id,name', 'assignedTo:id,name'])
            ->withCount(['lines', 'lines as picked_lines_count' => fn ($q) => $q->where('status', PickListLine::STATUS_PICKED)])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (PickList $p) => [
                'id' => $p->id,
                'warehouse_name' => $p->warehouse?->name,
                'assigned_to_name' => $p->assignedTo?->name,
                'status' => $p->status,
                'line_count' => $p->lines_count,
                'picked_lines_count' => $p->picked_lines_count,
                'created_at_formatted' => $p->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Inventory/PickLists/Index', [
            'pickLists' => $pickLists,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(PickList $pickList): Response
    {
        // §3O "sequenced by location for walk efficiency (simple location sort in v1)" — a
        // pick list's line count is always small, so sorting the fetched collection is
        // simpler and safer than a cross-schema join for what's a cosmetic ordering.
        $lines = $pickList->lines()
            ->with(['product:id,sku,name', 'batch:id,batch_number', 'serial:id,serial_number', 'location:id,code', 'pickedBy:id,name'])
            ->get()
            ->sortBy(fn (PickListLine $l) => $l->location?->code);

        return Inertia::render('Inventory/PickLists/Show', [
            'pickList' => [
                'id' => $pickList->id,
                'warehouse_id' => $pickList->warehouse_id,
                'warehouse_name' => $pickList->warehouse?->name,
                'status' => $pickList->status,
                'assigned_to' => $pickList->assigned_to,
                'created_at_formatted' => $pickList->created_at?->format('d M Y H:i'),
                'completed_at_formatted' => $pickList->completed_at?->format('d M Y H:i'),
            ],
            'lines' => $lines->map(fn (PickListLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'product_sku' => $l->product?->sku,
                'product_name' => $l->product?->name,
                'location_id' => $l->location_id,
                'location_code' => $l->location?->code,
                'batch_number' => $l->batch?->batch_number,
                'serial_number' => $l->serial?->serial_number,
                'qty' => (float) $l->qty,
                'confirmed_qty' => $l->confirmed_qty !== null ? (float) $l->confirmed_qty : null,
                'status' => $l->status,
                'picked_at_formatted' => $l->picked_at?->format('d M Y H:i'),
                'picked_by_name' => $l->pickedBy?->name,
            ]),
            'assignees' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assign(Request $request, PickList $pickList)
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer']);

        $this->service->assign($pickList, $data['assigned_to'] ?? null);

        return back()->with('success', 'Pick list assignment updated.');
    }

    public function pickLine(Request $request, PickList $pickList, PickListLine $line)
    {
        $data = $request->validate(['confirmed_qty' => 'required|numeric|min:0.0001']);

        $this->service->pickLine($line, (float) $data['confirmed_qty']);

        return back()->with('success', 'Line picked.');
    }

    public function destroy(PickList $pickList)
    {
        $this->service->delete($pickList);

        return redirect()->route('inventory.pickLists.index')->with('success', 'Pick list deleted.');
    }
}
