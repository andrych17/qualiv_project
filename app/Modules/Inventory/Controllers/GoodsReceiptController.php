<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Requests\UpdateGoodsReceiptRequest;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §3D Goods Receipt (Entry / Engine). */
class GoodsReceiptController extends Controller
{
    private const SORTABLE = ['receipt_date', 'reference_number', 'created_at'];

    public function __construct(protected GoodsReceiptService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'warehouse_id', 'sort', 'direction', 'per_page');

        $receipts = GoodsReceipt::query()
            ->with('warehouse:id,name')
            ->withCount('lines')
            ->filter($filters)
            ->when(
                $filters['sort'] ?? null,
                fn ($query) => TableQuery::applySort($query, $filters['sort'], $filters['direction'] ?? null, self::SORTABLE, 'id', 'desc'),
                fn ($query) => $query->orderByDesc('id'),
            )
            ->paginate(TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20))
            ->withQueryString()
            ->through(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'reference_number' => $r->reference_number,
                'warehouse_name' => $r->warehouse?->name,
                'receipt_date_formatted' => $r->receipt_date?->format('d M Y'),
                'line_count' => $r->lines_count,
                'status' => $r->status,
            ]);

        return Inertia::render('Inventory/GoodsReceipts/Index', [
            'receipts' => $receipts,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/GoodsReceipts/Create', $this->formProps());
    }

    public function store(StoreGoodsReceiptRequest $request)
    {
        $receipt = $this->service->create($request->validated());

        return redirect()->route('inventory.goodsReceipts.edit', $receipt)->with('success', 'Receipt saved as draft.');
    }

    public function edit(GoodsReceipt $goodsReceipt): Response
    {
        return Inertia::render('Inventory/GoodsReceipts/Edit', [
            ...$this->formProps(),
            'receipt' => $this->toFormData($goodsReceipt),
        ]);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt)
    {
        $this->service->update($goodsReceipt, $request->validated());

        return redirect()->route('inventory.goodsReceipts.edit', $goodsReceipt)->with('success', 'Receipt updated.');
    }

    public function destroy(GoodsReceipt $goodsReceipt)
    {
        $this->service->delete($goodsReceipt);

        return redirect()->route('inventory.goodsReceipts.index')->with('success', 'Receipt deleted.');
    }

    public function post(GoodsReceipt $goodsReceipt)
    {
        $this->service->post($goodsReceipt);

        return redirect()->route('inventory.goodsReceipts.edit', $goodsReceipt)->with('success', 'Receipt posted — stock is now on hand.');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'uoms' => Uom::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
            // §3L: lets the line form know, without a round trip, whether a product needs a
            // lot number — the shared async product-search response has no room for extra
            // fields (see AsyncSearchRegistry::search()'s fixed item shape).
            'productTracking' => Product::query()->where('is_active', true)->pluck('tracking_mode', 'id'),
        ];
    }

    /** @return array<string, mixed> */
    private function toFormData(GoodsReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'warehouse_id' => $receipt->warehouse_id,
            'receipt_date' => $receipt->receipt_date->toDateString(),
            'subject_type' => $receipt->subject_type,
            'subject_id' => $receipt->subject_id,
            'reference_number' => $receipt->reference_number,
            'status' => $receipt->status,
            'lines' => $receipt->lines->map(fn ($l) => [
                'product_id' => $l->product_id,
                'qty' => (float) $l->qty,
                'uom_id' => $l->uom_id,
                'unit_cost' => (float) $l->unit_cost,
                'destination_location_id' => $l->destination_location_id,
                'batch_number' => $l->batch?->batch_number,
                'batch_expiry_date' => $l->batch?->expiry_date?->toDateString(),
                'batch_manufacture_date' => $l->batch?->manufacture_date?->toDateString(),
                'batch_supplier_reference' => $l->batch?->supplier_reference,
                'serial_numbers' => $l->serial_numbers ?? [],
            ]),
        ];
    }
}
