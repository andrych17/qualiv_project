<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreAdjustmentRequest;
use App\Modules\Inventory\Requests\UpdateAdjustmentRequest;
use App\Modules\Inventory\Services\AdjustmentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3G Adjustments (Entry). */
class AdjustmentController extends Controller
{
    private const SORTABLE = ['adjustment_date', 'created_at'];

    public function __construct(
        protected AdjustmentService $service,
        protected InventoryService $inventory,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('status', 'warehouse_id', 'sort', 'direction', 'per_page');

        $adjustments = Adjustment::query()
            ->with(['warehouse:id,name', 'reason:id,name'])
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (Adjustment $a) => [
                'id' => $a->id,
                'warehouse_name' => $a->warehouse?->name,
                'reason_name' => $a->reason?->name,
                'adjustment_date_formatted' => $a->adjustment_date?->format('d M Y'),
                'line_count' => $a->lines_count,
                'status' => $a->status,
            ]);

        return Inertia::render('Inventory/Adjustments/Index', [
            'adjustments' => $adjustments,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Adjustments/Create', $this->formProps());
    }

    public function store(StoreAdjustmentRequest $request)
    {
        $adjustment = $this->service->create($request->validated());

        return redirect()->route('inventory.adjustments.edit', $adjustment)->with('success', 'Adjustment saved as draft.');
    }

    public function edit(Adjustment $adjustment): Response
    {
        return Inertia::render('Inventory/Adjustments/Edit', [
            ...$this->formProps(),
            'adjustment' => $this->toFormData($adjustment),
        ]);
    }

    public function update(UpdateAdjustmentRequest $request, Adjustment $adjustment)
    {
        $this->service->update($adjustment, $request->validated());

        return redirect()->route('inventory.adjustments.edit', $adjustment)->with('success', 'Adjustment updated.');
    }

    public function destroy(Adjustment $adjustment)
    {
        $this->service->delete($adjustment);

        return redirect()->route('inventory.adjustments.index')->with('success', 'Adjustment deleted.');
    }

    public function post(Adjustment $adjustment)
    {
        $this->service->post($adjustment);

        return redirect()->route('inventory.adjustments.edit', $adjustment)->with('success', 'Adjustment posted.');
    }

    /**
     * §3G "system quantity (auto-filled from current balance)" — live lookup as a line's
     * product/location is chosen. Physical on-hand, not available-to-promise (§3N): a count
     * needs to match what's actually on the shelf, unaffected by soft reservation holds.
     */
    public function balance(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'location_id' => 'required|integer',
            'batch_id' => 'nullable|integer',
        ]);

        return response()->json([
            'qty_on_hand' => $this->inventory->onHandQty($data['product_id'], $data['warehouse_id'], $data['location_id'], $data['batch_id'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            'reasons' => AdjustmentReason::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'productTracking' => Product::query()->where('is_active', true)->pluck('tracking_mode', 'id'),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(Adjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'warehouse_id' => $adjustment->warehouse_id,
            'location_id' => $adjustment->location_id,
            'adjustment_date' => $adjustment->adjustment_date->toDateString(),
            'reason_id' => $adjustment->reason_id,
            'reference' => $adjustment->reference,
            'status' => $adjustment->status,
            'lines' => $adjustment->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'system_qty' => $l->system_qty !== null ? (float) $l->system_qty : null,
                'counted_qty' => (float) $l->counted_qty,
                'batch_id' => $l->batch_id,
                'batch_label' => $l->batch?->batch_number,
            ]),
        ];
    }
}
