<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PackList;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreShipmentRequest;
use App\Modules\Inventory\Requests\UpdateShipmentRequest;
use App\Modules\Inventory\Services\ShipmentService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3P Shipping (Entry / Engine). */
class ShipmentController extends Controller
{
    private const SORTABLE = ['ship_date', 'created_at'];

    public function __construct(protected ShipmentService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'warehouse_id', 'sort', 'direction', 'per_page');

        $shipments = Shipment::query()
            ->with('warehouse:id,name')
            ->withCount('packLists')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Shipment $s) => [
                'id' => $s->id,
                'warehouse_name' => $s->warehouse?->name,
                'carrier' => $s->carrier,
                'tracking_number' => $s->tracking_number,
                'ship_date_formatted' => $s->ship_date?->format('d M Y'),
                'package_count' => $s->pack_lists_count,
                'status' => $s->status,
            ]);

        return Inertia::render('Inventory/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Shipments/Create', $this->formProps());
    }

    public function store(StoreShipmentRequest $request)
    {
        $shipment = $this->service->create($request->validated());

        return redirect()->route('inventory.shipments.edit', $shipment)->with('success', 'Shipment created.');
    }

    public function edit(Shipment $shipment): Response
    {
        return Inertia::render('Inventory/Shipments/Edit', [
            ...$this->formProps(warehouseId: $shipment->warehouse_id, excludeShipmentId: $shipment->id),
            'shipment' => $this->toFormData($shipment),
        ]);
    }

    public function update(UpdateShipmentRequest $request, Shipment $shipment)
    {
        $this->service->update($shipment, $request->validated());

        return redirect()->route('inventory.shipments.edit', $shipment)->with('success', 'Shipment updated.');
    }

    public function destroy(Shipment $shipment)
    {
        $this->service->delete($shipment);

        return redirect()->route('inventory.shipments.index')->with('success', 'Shipment deleted.');
    }

    public function shipConfirm(Shipment $shipment)
    {
        $this->service->shipConfirm($shipment);

        return redirect()->route('inventory.shipments.edit', $shipment)->with('success', 'Shipment confirmed — stock has been deducted.');
    }

    public function markDelivered(Shipment $shipment)
    {
        $this->service->markDelivered($shipment);

        return redirect()->route('inventory.shipments.edit', $shipment)->with('success', 'Shipment marked delivered.');
    }

    /** @return array<string, mixed> */
    private function formProps(?int $warehouseId = null, ?int $excludeShipmentId = null): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'eligiblePackLists' => $this->eligiblePackLists($warehouseId, $excludeShipmentId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function eligiblePackLists(?int $warehouseId, ?int $excludeShipmentId): array
    {
        return PackList::query()
            ->with(['pickList:id'])
            ->where('status', PackList::STATUS_PACKED)
            ->where(function ($q) use ($excludeShipmentId) {
                $q->whereNull('shipment_id')->when($excludeShipmentId, fn ($q, $id) => $q->orWhere('shipment_id', $id));
            })
            ->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PackList $p) => [
                'id' => $p->id,
                'warehouse_id' => $p->warehouse_id,
                'pick_list_id' => $p->pick_list_id,
                'package_type' => $p->package_type,
                'weight' => $p->weight !== null ? (float) $p->weight : null,
                'weight_uom' => $p->weight_uom,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function toFormData(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'warehouse_id' => $shipment->warehouse_id,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'ship_date' => $shipment->ship_date?->toDateString(),
            'status' => $shipment->status,
            'goods_issue_id' => $shipment->goods_issue_id,
            'pack_list_ids' => $shipment->packLists()->pluck('id'),
        ];
    }
}
