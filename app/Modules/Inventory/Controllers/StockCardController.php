<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockBalanceRebuildService;
use App\Shared\Helpers\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3H Stock Card — a read-only report over `stock_ledger`, always reconstructable from the
 * ledger alone. Running balance/value is computed over the product's FULL history (optionally
 * narrowed to a warehouse/location scope) before date-range/movement-type filters are applied
 * as a display-only slice — otherwise a filtered "running balance" would read as wrong (e.g.
 * filtering to just Adjustments would make the balance ignore every receipt/issue).
 */
class StockCardController extends Controller
{
    private const REFERENCE_ROUTES = [
        'inventory.goods_receipts' => ['label' => 'Receipt', 'route' => 'inventory.goodsReceipts.edit'],
        'inventory.goods_issues' => ['label' => 'Issue', 'route' => 'inventory.goodsIssues.edit'],
        'inventory.transfers' => ['label' => 'Transfer', 'route' => 'inventory.transfers.edit'],
        'inventory.adjustments' => ['label' => 'Adjustment', 'route' => 'inventory.adjustments.edit'],
    ];

    public function index(Request $request, StockBalanceRebuildService $rebuild): Response
    {
        $filters = $request->only('product_id', 'warehouse_id', 'location_id', 'movement_type', 'date_from', 'date_to', 'per_page');

        $product = null;
        $rows = null;
        $summary = null;

        if (! empty($filters['product_id'])) {
            $product = Product::query()->with('baseUom:id,code')->find($filters['product_id']);
        }

        if ($product) {
            $warehouseId = $filters['warehouse_id'] ?? null;
            $locationId = $filters['location_id'] ?? null;

            $ledger = StockLedger::query()
                ->where('product_id', $product->id)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->with(['warehouse:id,name', 'location:id,code'])
                ->orderBy('movement_date')
                ->orderBy('id')
                ->get();

            $computed = $this->withRunningTotals($ledger);
            $filtered = $this->applyDisplayFilters($computed, $filters);

            $rows = $this->paginate($filtered, $request, $filters);

            $ledgerQty = $ledger->isEmpty() ? 0.0 : (float) $computed->last()['running_qty'];
            $cachedQty = $this->cachedBalance($product->id, $warehouseId, $locationId);

            $summary = [
                'ledger_qty' => $ledgerQty,
                'cached_qty' => $cachedQty,
                'drifted' => abs($ledgerQty - $cachedQty) > 0.00005,
            ];
        }

        return Inertia::render('Inventory/StockCard/Index', [
            'product' => $product ? [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'base_uom_code' => $product->baseUom?->code,
            ] : null,
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locations' => Location::query()->where('is_active', true)->orderBy('code')->get(['id', 'warehouse_id', 'code']),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> ascending, each row carrying its true cumulative balance */
    private function withRunningTotals(Collection $ledger): Collection
    {
        $runningQty = 0.0;
        $runningValue = 0.0;

        return $ledger->map(function (StockLedger $l) use (&$runningQty, &$runningValue) {
            $qty = (float) $l->qty;
            $runningQty += $qty;
            $runningValue += (float) $l->total_value;

            $reference = self::REFERENCE_ROUTES[$l->subject_type] ?? null;

            return [
                'id' => $l->id,
                'movement_date' => $l->movement_date->toDateString(),
                'movement_date_formatted' => $l->movement_date->format('d M Y'),
                'movement_type' => $l->movement_type,
                'warehouse_name' => $l->warehouse?->name,
                'location_code' => $l->location?->code,
                'qty_in' => $qty > 0 ? $qty : 0.0,
                'qty_out' => $qty < 0 ? abs($qty) : 0.0,
                'unit_cost' => (float) $l->unit_cost,
                'running_qty' => $runningQty,
                'running_value' => $runningValue,
                'reference_label' => $reference && $l->subject_id ? "{$reference['label']} #{$l->subject_id}" : ($l->subject_type ?? '—'),
                'reference_url' => $reference && $l->subject_id ? route($reference['route'], $l->subject_id) : null,
            ];
        });
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function applyDisplayFilters(Collection $rows, array $filters): Collection
    {
        return $rows->filter(function (array $row) use ($filters) {
            if (! empty($filters['movement_type']) && $row['movement_type'] !== $filters['movement_type']) {
                return false;
            }
            if (! empty($filters['date_from']) && $row['movement_date'] < $filters['date_from']) {
                return false;
            }
            if (! empty($filters['date_to']) && $row['movement_date'] > $filters['date_to']) {
                return false;
            }

            return true;
        })->values();
    }

    /** Manual pagination over an already-computed collection — see the class docblock for why running totals can't be paginated at the query level. */
    private function paginate(Collection $rows, Request $request, array $filters): LengthAwarePaginator
    {
        $perPage = TableQuery::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null, 20);
        $page = (int) $request->input('page', 1);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function cachedBalance(int $productId, ?int $warehouseId, ?int $locationId): float
    {
        return (float) StockBalance::query()
            ->where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->sum('qty_on_hand');
    }
}
