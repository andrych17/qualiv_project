<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3I Inventory Valuation Engine — two read-only report modes over the same row shape
 * (product × warehouse): "live" sums the open (unconsumed) `stock_valuation_layers` — cheap,
 * always current, mirrors `stock_balances`' role as a fast cache of ledger truth. "As of"
 * a past date instead sums `stock_ledger.total_value` up to that date — no separate
 * period-close process, since every ledger row already carries its own signed value
 * (receipts +, issues/transfers-out −, see StockCardController's running_value for the same
 * pattern) and is the source of truth for any point in time, not just today.
 *
 * Grouping/subtotals by category or warehouse are left to the frontend's DataTable groupBy
 * (same pattern as Projects' issue board) rather than a server-side GROUP BY per dimension —
 * one flat row set, several client-side views.
 */
class InventoryValuationController extends Controller
{
    private const EPSILON = 0.00005;

    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'category_id', 'warehouse_id', 'as_of_date');
        $asOfDate = $filters['as_of_date'] ?? null;

        $rows = $asOfDate ? $this->snapshotRows($asOfDate, $filters) : $this->liveRows($filters);

        return Inertia::render('Inventory/Valuation/Index', [
            'rows' => $rows->values(),
            'filters' => $filters,
            'summary' => [
                'total_value' => round((float) $rows->sum('total_value'), 4),
                'row_count' => $rows->count(),
                'as_of_date' => $asOfDate,
            ],
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Current on-hand value, summed from open valuation layers (§3I: "open (unconsumed) stock_valuation_layers"). */
    private function liveRows(array $filters): Collection
    {
        $rows = StockValuationLayer::query()
            ->where('remaining_qty', '>', self::EPSILON)
            ->with(['product:id,sku,name,category_id', 'product.category:id,name', 'warehouse:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->get()
            ->filter(fn (StockValuationLayer $l) => $l->product !== null)
            ->groupBy(fn (StockValuationLayer $l) => "{$l->product_id}:{$l->warehouse_id}")
            ->map(function (Collection $layers) {
                $first = $layers->first();
                $qty = (float) $layers->sum('remaining_qty');
                $value = (float) $layers->sum(fn (StockValuationLayer $l) => (float) $l->remaining_qty * (float) $l->unit_cost);

                return $this->toRow($first->product, $first->warehouse_id, $first->warehouse?->name, $qty, $value);
            })
            ->values();

        return $this->applyDisplayFilters($rows, $filters);
    }

    /** Point-in-time value as of a past date, replayed from the ledger (§3I: "no separate closing process"). */
    private function snapshotRows(string $asOfDate, array $filters): Collection
    {
        $sums = StockLedger::query()
            ->where('movement_date', '<=', $asOfDate)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->selectRaw('product_id, warehouse_id, SUM(qty) as qty, SUM(total_value) as total_value')
            ->groupBy('product_id', 'warehouse_id')
            ->havingRaw('SUM(qty) > ?', [self::EPSILON])
            ->get();

        $products = Product::query()
            ->with('category:id,name')
            ->whereIn('id', $sums->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        $warehouses = Warehouse::query()
            ->whereIn('id', $sums->pluck('warehouse_id')->unique())
            ->get()
            ->keyBy('id');

        $rows = $sums
            ->filter(fn ($row) => $products->has($row->product_id))
            ->map(fn ($row) => $this->toRow(
                $products[$row->product_id],
                $row->warehouse_id,
                $warehouses[$row->warehouse_id]?->name,
                (float) $row->qty,
                (float) $row->total_value,
            ))
            ->values();

        return $this->applyDisplayFilters($rows, $filters);
    }

    /** @return array<string, mixed> */
    private function toRow(Product $product, int $warehouseId, ?string $warehouseName, float $qty, float $value): array
    {
        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name ?? 'Uncategorized',
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'qty' => $qty,
            'unit_cost' => $qty > self::EPSILON ? round($value / $qty, 6) : 0.0,
            'total_value' => round($value, 4),
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function applyDisplayFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when($filters['category_id'] ?? null, fn ($rows, $v) => $rows->filter(fn ($r) => $r['category_id'] == $v)->values())
            ->when($filters['search'] ?? null, function (Collection $rows, $v) {
                $needle = mb_strtolower($v);

                return $rows->filter(fn ($r) => str_contains(mb_strtolower($r['sku']), $needle) || str_contains(mb_strtolower($r['product_name']), $needle))->values();
            });
    }
}
