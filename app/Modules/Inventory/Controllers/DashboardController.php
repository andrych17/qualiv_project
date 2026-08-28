<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\CycleCountLine;
use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Models\StockValuationLayer;
use App\Modules\Inventory\Models\Transfer;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §3A Main Dashboard (Inventory Overview) — read-only aggregate over the engines built by
 * every other §3 section; adds no new tables of its own. "Low stock" compares
 * `stock_balances.qty_on_hand` (summed across every warehouse — `products.reorder_point` is
 * not itself warehouse-specific, so this is always the "globally" case the spec allows for)
 * against `products.reorder_point`. Out-of-stock and negative-variance rows are surfaced first
 * in the Needs Attention feed regardless of the tabbed table's own sort, per spec.
 */
class DashboardController extends Controller
{
    private const EPSILON = 0.00005;

    private const EXPIRY_WINDOW_DAYS = 30;

    public function __invoke(): Response
    {
        $lowStock = $this->lowStockRows();
        $expiringBatches = $this->expiringBatches();
        $openVariances = $this->openCountVariances();

        return Inertia::render('Inventory/Dashboard', [
            'metrics' => [
                'total_skus' => Product::query()->where('is_active', true)->count(),
                'on_hand_value' => $this->onHandValue(),
                'low_stock_count' => $lowStock->where('qty', '>', self::EPSILON)->count(),
                'out_of_stock_count' => $lowStock->where('qty', '<=', self::EPSILON)->count(),
                'pending_receipts_count' => GoodsReceipt::query()->where('status', GoodsReceipt::STATUS_DRAFT)->count(),
                'pending_shipments_count' => Shipment::query()->where('status', Shipment::STATUS_PENDING)->count(),
                'open_cycle_counts_count' => CycleCount::query()->whereIn('status', [CycleCount::STATUS_PENDING, CycleCount::STATUS_IN_PROGRESS])->count(),
            ],
            'needsAttention' => $this->needsAttention($lowStock, $expiringBatches, $openVariances),
            'lowStock' => $lowStock->values(),
            'recentMovements' => $this->recentMovements(),
            'pendingDocuments' => $this->pendingDocuments(),
            'openCounts' => $this->openCounts(),
        ]);
    }

    /** §3I: same "open valuation layers" formula InventoryValuationController uses for its live total. */
    private function onHandValue(): float
    {
        return round((float) (StockValuationLayer::query()
            ->where('remaining_qty', '>', self::EPSILON)
            ->selectRaw('COALESCE(SUM(remaining_qty * unit_cost), 0) as total')
            ->value('total') ?? 0), 4);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function lowStockRows(): Collection
    {
        $balances = StockBalance::query()
            ->selectRaw('product_id, SUM(qty_on_hand) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        return Product::query()
            ->where('is_active', true)
            ->where('reorder_point', '>', 0)
            ->get(['id', 'sku', 'name', 'reorder_point'])
            ->map(fn (Product $p) => [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'product_name' => $p->name,
                'qty' => (float) ($balances[$p->id] ?? 0),
                'reorder_point' => (float) $p->reorder_point,
            ])
            ->filter(fn (array $row) => $row['qty'] < $row['reorder_point'] - self::EPSILON)
            ->sortBy('qty')
            ->values();
    }

    /** @return Collection<int, StockBatch> */
    private function expiringBatches(): Collection
    {
        $batchIdsWithStock = StockBalance::query()
            ->whereNotNull('batch_id')
            ->where('qty_on_hand', '>', 0)
            ->distinct()
            ->pluck('batch_id');

        return StockBatch::query()
            ->whereIn('id', $batchIdsWithStock)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(self::EXPIRY_WINDOW_DAYS))
            ->with('product:id,sku,name')
            ->orderBy('expiry_date')
            ->limit(20)
            ->get();
    }

    /** §3Q: a draft Adjustment CycleCountService::complete() drafted, still awaiting review/post. */
    private function openCountVariances(): Collection
    {
        return Adjustment::query()
            ->where('status', Adjustment::STATUS_DRAFT)
            ->where('reference', 'like', 'Cycle Count%')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lowStock
     * @param  Collection<int, StockBatch>  $expiringBatches
     * @param  Collection<int, Adjustment>  $openVariances
     * @return list<array<string, mixed>>
     */
    private function needsAttention(Collection $lowStock, Collection $expiringBatches, Collection $openVariances): array
    {
        $items = collect();

        foreach ($lowStock as $row) {
            $outOfStock = $row['qty'] <= self::EPSILON;
            $items->push([
                'type' => 'Low Stock',
                'label' => "{$row['sku']} — {$row['product_name']}",
                'detail' => $outOfStock ? 'Out of stock' : "{$row['qty']} on hand, below reorder point of {$row['reorder_point']}",
                'rail' => $outOfStock ? 'danger' : 'warning',
                'href' => route('inventory.products.edit', $row['product_id']),
            ]);
        }

        foreach ($expiringBatches as $batch) {
            $expired = $batch->isExpiredAsOf();
            $items->push([
                'type' => 'Expiring Batch',
                'label' => "Lot {$batch->batch_number} — {$batch->product?->sku}",
                'detail' => $expired ? "Expired {$batch->expiry_date->format('d M Y')}" : "Expires {$batch->expiry_date->format('d M Y')}",
                'rail' => $expired ? 'danger' : 'warning',
                'href' => route('inventory.batches.edit', $batch->id),
            ]);
        }

        foreach ($openVariances as $adjustment) {
            $items->push([
                'type' => 'Count Variance',
                'label' => $adjustment->reference,
                'detail' => 'Adjustment draft awaiting review before posting',
                'rail' => 'warning',
                'href' => route('inventory.adjustments.edit', $adjustment->id),
            ]);
        }

        return $items
            ->sortBy(fn (array $i) => $i['rail'] === 'danger' ? 0 : 1)
            ->take(15)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentMovements(): array
    {
        return StockLedger::query()
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (StockLedger $l) => [
                'id' => $l->id,
                'product_sku' => $l->product?->sku,
                'product_name' => $l->product?->name,
                'movement_type' => $l->movement_type,
                'qty' => (float) $l->qty,
                'warehouse_name' => $l->warehouse?->name,
                'movement_date_formatted' => $l->movement_date->format('d M Y'),
                'rail' => $l->movement_type === StockLedger::TYPE_ADJUSTMENT && (float) $l->qty < 0 ? 'danger' : 'info',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function pendingDocuments(): array
    {
        $receipts = GoodsReceipt::query()
            ->where('status', GoodsReceipt::STATUS_DRAFT)
            ->with('warehouse:id,name')
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (GoodsReceipt $r) => [
                'type' => 'Goods Receipt', 'reference' => $r->reference_number ?? "#{$r->id}",
                'warehouse_name' => $r->warehouse?->name, 'date_formatted' => $r->receipt_date?->format('d M Y'),
                'status' => $r->status, 'href' => route('inventory.goodsReceipts.edit', $r->id), 'rail' => 'neutral',
            ]);

        $issues = GoodsIssue::query()
            ->where('status', GoodsIssue::STATUS_DRAFT)
            ->with('warehouse:id,name')
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (GoodsIssue $i) => [
                'type' => 'Goods Issue', 'reference' => "#{$i->id}",
                'warehouse_name' => $i->warehouse?->name, 'date_formatted' => $i->issue_date?->format('d M Y'),
                'status' => $i->status, 'href' => route('inventory.goodsIssues.edit', $i->id), 'rail' => 'neutral',
            ]);

        $transfers = Transfer::query()
            ->whereIn('status', [Transfer::STATUS_DRAFT, Transfer::STATUS_IN_TRANSIT])
            ->with('sourceWarehouse:id,name')
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (Transfer $t) => [
                'type' => 'Transfer', 'reference' => "#{$t->id}",
                'warehouse_name' => $t->sourceWarehouse?->name, 'date_formatted' => $t->transfer_date?->format('d M Y'),
                'status' => $t->status, 'href' => route('inventory.transfers.edit', $t->id), 'rail' => 'neutral',
            ]);

        $shipments = Shipment::query()
            ->where('status', Shipment::STATUS_PENDING)
            ->with('warehouse:id,name')
            ->orderByDesc('id')->limit(5)->get()
            ->map(fn (Shipment $s) => [
                'type' => 'Shipment', 'reference' => "#{$s->id}",
                'warehouse_name' => $s->warehouse?->name, 'date_formatted' => $s->ship_date?->format('d M Y'),
                'status' => $s->status, 'href' => route('inventory.shipments.edit', $s->id), 'rail' => 'neutral',
            ]);

        return $receipts->concat($issues)->concat($transfers)->concat($shipments)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function openCounts(): array
    {
        return CycleCount::query()
            ->whereIn('status', [CycleCount::STATUS_PENDING, CycleCount::STATUS_IN_PROGRESS])
            ->with(['warehouse:id,name', 'location:id,code', 'category:id,name', 'assignedTo:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($q) => $q->where('status', CycleCountLine::STATUS_COUNTED)])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CycleCount $c) => [
                'id' => $c->id,
                'warehouse_name' => $c->warehouse?->name,
                'scope' => $c->location ? "Location {$c->location->code}" : ($c->category ? "Category {$c->category->name}" : "ABC class {$c->abc_class}"),
                'assigned_to_name' => $c->assignedTo?->name,
                'progress' => "{$c->counted_lines_count} / {$c->lines_count}",
                'status' => $c->status,
                'href' => route('inventory.cycleCounts.show', $c->id),
                'rail' => $c->status === CycleCount::STATUS_IN_PROGRESS ? 'warning' : 'neutral',
            ])
            ->all();
    }
}
