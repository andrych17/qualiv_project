<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptLine;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Services\Costing\CostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3D Goods Receipt — draft CRUD plus the one action that touches the ledger: post(). */
class GoodsReceiptService
{
    private const EPSILON = 0.0000005;

    public function __construct(
        protected CostingService $costing,
        protected StockBalanceService $balances,
        protected UomConversionResolver $uomResolver,
        protected AccountingCompanyResolver $companyResolver,
        protected BatchService $batches,
        protected SerialService $serials,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
            $receipt = GoodsReceipt::query()->create([
                ...$this->headerAttributes($data),
                'status' => GoodsReceipt::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($receipt, $data['lines'] ?? []);

            return $receipt->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(GoodsReceipt $receipt, array $data): GoodsReceipt
    {
        $this->assertDraft($receipt);

        return DB::transaction(function () use ($receipt, $data) {
            $receipt->update($this->headerAttributes($data));
            $this->syncLines($receipt, $data['lines'] ?? []);

            return $receipt->refresh()->load('lines');
        });
    }

    public function delete(GoodsReceipt $receipt): void
    {
        $this->assertDraft($receipt);
        $receipt->delete();
    }

    /**
     * §3D: posting is the only action that touches the ledger — draft receipts can be
     * edited freely, posted receipts are immutable (correct via a reversing Adjustment,
     * never an edit). Each line: creates one `stock_ledger` entry, a valuation layer,
     * updates `stock_balances`, then (after the transaction commits) dispatches
     * Accounting's InventoryGoodsReceived per ledger row — its idempotency key.
     */
    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        $this->assertDraft($receipt);

        $lines = $receipt->lines()->with('product')->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
        }

        // Fixed lock order (§5 concurrency note): every posting flow (receive/issue, and
        // transfer/adjust later) touches balances-then-layers sorted by (product, location)
        // so concurrent postings on overlapping rows never wait on each other in reverse.
        $lines = $lines->sortBy([['product_id', 'asc'], ['destination_location_id', 'asc']])->values();

        $ledgerRows = DB::transaction(function () use ($receipt, $lines) {
            $rows = [];

            foreach ($lines as $line) {
                $product = $line->product;

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is inactive and can't receive new stock."]);
                }
                if ($line->destination_location_id === null) {
                    throw ValidationException::withMessages(['lines' => 'Every line needs a destination location before posting.']);
                }
                if ($product->tracking_mode === Product::TRACKING_BATCH && $line->batch_id === null) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is batch-tracked — every line needs a lot number before posting."]);
                }
                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->assertSerialCountMatchesQty($product, $line->serial_numbers ?? [], (float) $line->qty);
                }
                $this->assertLocationInWarehouse($line->destination_location_id, $receipt->warehouse_id);

                [$baseQty, $baseUnitCost] = $this->uomResolver->toBaseUnits(
                    $product, $line->uom_id, (float) $line->qty, (float) $line->unit_cost,
                );

                $balance = $this->balances->lockOrCreate($product->id, $receipt->warehouse_id, $line->destination_location_id, $line->batch_id);

                $ledger = StockLedger::query()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'location_id' => $line->destination_location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => StockLedger::TYPE_RECEIPT,
                    'qty' => $baseQty,
                    'unit_cost' => $baseUnitCost,
                    'total_value' => $baseQty * $baseUnitCost,
                    'subject_type' => 'inventory.goods_receipts',
                    'subject_id' => (string) $receipt->id,
                    'movement_date' => $receipt->receipt_date,
                    'created_by' => auth()->id(),
                ]);

                $this->costing->strategyFor($product)->costReceipt($product->id, $receipt->warehouse_id, $baseQty, $baseUnitCost, $ledger->id, $line->batch_id);
                $this->balances->applyDelta($balance, $baseQty);

                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->serials->receive($product->id, $line->serial_numbers ?? [], $receipt->warehouse_id, $line->destination_location_id, $ledger->id);
                }

                $rows[] = $ledger;
            }

            $receipt->update(['status' => GoodsReceipt::STATUS_POSTED, 'posted_at' => now()]);

            return $rows;
        });

        $this->dispatchAccountingEvents($ledgerRows);

        return $receipt->refresh()->load('lines');
    }

    /** @param  list<StockLedger>  $ledgerRows */
    private function dispatchAccountingEvents(array $ledgerRows): void
    {
        $companyId = $this->companyResolver->resolve();
        if ($companyId === null) {
            return;
        }

        foreach ($ledgerRows as $ledger) {
            InventoryGoodsReceived::dispatch(
                $companyId,
                $ledger->product_id,
                (float) $ledger->qty,
                (float) $ledger->unit_cost,
                abs((float) $ledger->total_value),
                $ledger->movement_date->toDateString(),
                'inventory.stock_ledger',
                (string) $ledger->id,
            );
        }
    }

    private function assertDraft(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== GoodsReceipt::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'This receipt is already posted and can no longer be edited.']);
        }
    }

    private function assertLocationInWarehouse(int $locationId, int $warehouseId): void
    {
        $belongs = Location::query()->where('id', $locationId)->where('warehouse_id', $warehouseId)->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['lines' => 'A line\'s destination location does not belong to this receipt\'s warehouse.']);
        }
    }

    /**
     * §3M: a serial-tracked line has no meaningful fractional quantity (a case-pack UoM
     * conversion could otherwise hand back e.g. 11.9999 from a bad factor) — the entered
     * qty must be a whole number, and the count of serial numbers entered must match it
     * exactly, one row per physical unit.
     *
     * @param  array<int, mixed>  $serialNumbers
     */
    private function assertSerialCountMatchesQty(Product $product, array $serialNumbers, float $qty): void
    {
        if (abs($qty - round($qty)) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — quantity must be a whole number."]);
        }
        if (abs(count($serialNumbers) - $qty) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — enter exactly {$qty} serial number(s), one per unit."]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'warehouse_id' => $data['warehouse_id'],
            'receipt_date' => $data['receipt_date'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
        ];
    }

    /**
     * §3L: a batch-tracked line's free-text lot number (+ optional expiry/manufacture/
     * supplier reference) is resolved to a `batch_id` here, at draft-save — not deferred to
     * post() — so every downstream consumer (post(), the Edit form re-render) deals in a
     * plain FK like every other reference column, never a raw string.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(GoodsReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['product_id']) || empty($line['qty'])) {
                continue;
            }

            $batchId = null;
            if (! empty($line['batch_number'])) {
                $batchId = $this->batches->resolve(
                    $line['product_id'],
                    $line['batch_number'],
                    $line['batch_expiry_date'] ?? null,
                    $line['batch_manufacture_date'] ?? null,
                    $line['batch_supplier_reference'] ?? null,
                )->id;
            }

            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'product_id' => $line['product_id'],
                'batch_id' => $batchId,
                'qty' => $line['qty'],
                'uom_id' => $line['uom_id'],
                'unit_cost' => $line['unit_cost'] ?? 0,
                'destination_location_id' => $line['destination_location_id'] ?? null,
                'serial_numbers' => ! empty($line['serial_numbers']) ? array_values(array_filter($line['serial_numbers'])) : null,
            ]);
        }
    }
}
