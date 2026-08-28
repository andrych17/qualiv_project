<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StorePackListRequest;
use App\Modules\Inventory\Requests\UpdatePackListRequest;
use App\Modules\Inventory\Services\PackListService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3P Packing (Entry). No standalone create — always started from a PickList's Show page ("Create package"). */
class PackListController extends Controller
{
    private const SORTABLE = ['created_at', 'packed_at'];

    public function __construct(protected PackListService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('warehouse_id', 'status', 'sort', 'direction', 'per_page');

        $packLists = PackList::query()
            ->with(['warehouse:id,name', 'pickList:id', 'shipment:id,status'])
            ->withCount('lines')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (PackList $p) => [
                'id' => $p->id,
                'warehouse_name' => $p->warehouse?->name,
                'pick_list_id' => $p->pick_list_id,
                'shipment_id' => $p->shipment_id,
                'package_type' => $p->package_type,
                'weight' => $p->weight !== null ? (float) $p->weight : null,
                'weight_uom' => $p->weight_uom,
                'line_count' => $p->lines_count,
                'status' => $p->status,
                'packed_at_formatted' => $p->packed_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Inventory/PackLists/Index', [
            'packLists' => $packLists,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $pickList = $request->integer('pick_list_id')
            ? PickList::query()->with('warehouse:id,name')->find($request->integer('pick_list_id'))
            : null;

        return Inertia::render('Inventory/PackLists/Create', [
            'pickList' => $pickList ? $this->pickListSummary($pickList) : null,
            'availableLines' => $pickList ? $this->availableLines($pickList) : [],
            'eligiblePickLists' => $pickList ? [] : $this->eligiblePickLists(),
        ]);
    }

    public function store(StorePackListRequest $request)
    {
        $packList = $this->service->create($request->validated());

        return redirect()->route('inventory.packLists.edit', $packList)->with('success', 'Package created.');
    }

    public function edit(PackList $packList): Response
    {
        $pickList = $packList->pickList()->with('warehouse:id,name')->first();

        return Inertia::render('Inventory/PackLists/Edit', [
            'packList' => $this->toFormData($packList),
            'pickList' => $this->pickListSummary($pickList),
            'availableLines' => $this->availableLines($pickList, excludePackListId: $packList->id),
        ]);
    }

    public function update(UpdatePackListRequest $request, PackList $packList)
    {
        $this->service->update($packList, $request->validated());

        return redirect()->route('inventory.packLists.edit', $packList)->with('success', 'Package updated.');
    }

    public function destroy(PackList $packList)
    {
        $this->service->delete($packList);

        return redirect()->route('inventory.packLists.index')->with('success', 'Package deleted.');
    }

    /** @return array<string, mixed> */
    private function pickListSummary(PickList $pickList): array
    {
        return [
            'id' => $pickList->id,
            'warehouse_id' => $pickList->warehouse_id,
            'warehouse_name' => $pickList->warehouse?->name,
            'status' => $pickList->status,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function eligiblePickLists(): array
    {
        return PickList::query()
            ->whereHas('lines', fn ($q) => $q->where('status', PickListLine::STATUS_PICKED))
            ->with('warehouse:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PickList $p) => $this->pickListSummary($p))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function availableLines(PickList $pickList, ?int $excludePackListId = null): array
    {
        return $pickList->lines()
            ->with(['product:id,sku,name', 'batch:id,batch_number', 'serial:id,serial_number'])
            ->where('status', PickListLine::STATUS_PICKED)
            ->get()
            ->map(function (PickListLine $line) use ($excludePackListId) {
                $remaining = $this->service->remainingQty($line, $excludePackListId);

                return [
                    'id' => $line->id,
                    'product_sku' => $line->product?->sku,
                    'product_name' => $line->product?->name,
                    'batch_number' => $line->batch?->batch_number,
                    'serial_number' => $line->serial?->serial_number,
                    'confirmed_qty' => (float) $line->confirmed_qty,
                    'remaining_qty' => $remaining,
                ];
            })
            ->filter(fn ($row) => $row['remaining_qty'] > 0.00005)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function toFormData(PackList $packList): array
    {
        return [
            'id' => $packList->id,
            'pick_list_id' => $packList->pick_list_id,
            'package_type' => $packList->package_type,
            'weight' => $packList->weight !== null ? (float) $packList->weight : null,
            'weight_uom' => $packList->weight_uom,
            'length' => $packList->length !== null ? (float) $packList->length : null,
            'width' => $packList->width !== null ? (float) $packList->width : null,
            'height' => $packList->height !== null ? (float) $packList->height : null,
            'dimension_uom' => $packList->dimension_uom,
            'shipment_id' => $packList->shipment_id,
            'status' => $packList->status,
            'lines' => $packList->lines->map(fn ($l) => [
                'pick_list_line_id' => $l->pick_list_line_id,
                'qty' => (float) $l->qty,
            ]),
        ];
    }
}
