<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Inventory\Models\GoodsIssue;
use App\Modules\Inventory\Models\GoodsIssueLine;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLedger;
use App\Modules\Inventory\Services\Costing\CostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3E Goods Issue — draft CRUD plus the one action that touches the ledger: post(). */
class GoodsIssueService
{
    private const EPSILON = 0.0000005;

    public function __construct(
        protected CostingService $costing,
        protected StockBalanceService $balances,
        protected UomConversionResolver $uomResolver,
        protected AccountingCompanyResolver $companyResolver,
        protected SerialService $serials,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): GoodsIssue
    {
        return DB::transaction(function () use ($data) {
            $issue = GoodsIssue::query()->create([
                ...$this->headerAttributes($data),
                'status' => GoodsIssue::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($issue, $data['lines'] ?? []);

            return $issue->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(GoodsIssue $issue, array $data): GoodsIssue
    {
        $this->assertDraft($issue);

        return DB::transaction(function () use ($issue, $data) {
            $issue->update($this->headerAttributes($data));
            $this->syncLines($issue, $data['lines'] ?? []);

            return $issue->refresh()->load('lines');
        });
    }

    public function delete(GoodsIssue $issue): void
    {
        $this->assertDraft($issue);
        $issue->delete();
    }

    /**
     * §3E: blocks posting if requested quantity exceeds available (on-hand — reservations
     * aren't built yet, §3N Operational, so "available" is on-hand only for now) at that
     * location. Consumes valuation layers per the product's costing method, updates
     * `stock_balances`, then (after commit) dispatches Accounting's InventoryGoodsIssued.
     */
    public function post(GoodsIssue $issue): GoodsIssue
    {
        $this->assertDraft($issue);

        $lines = $issue->lines()->with(['product', 'batch'])->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
        }

        $lines = $lines->sortBy([['product_id', 'asc'], ['source_location_id', 'asc']])->values();

        $ledgerRows = DB::transaction(function () use ($issue, $lines) {
            $rows = [];

            foreach ($lines as $line) {
                $product = $line->product;

                if (! $product->is_active) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is inactive and can't be issued."]);
                }
                if ($line->source_location_id === null) {
                    throw ValidationException::withMessages(['lines' => 'Every line needs a source location before posting.']);
                }
                if ($product->tracking_mode === Product::TRACKING_BATCH && $line->batch_id === null) {
                    throw ValidationException::withMessages(['lines' => "{$product->sku} is batch-tracked — every line needs a lot selected before posting."]);
                }
                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->assertSerialCountMatchesQty($product, $line->serial_numbers ?? [], (float) $line->qty);
                }
                $this->assertLocationInWarehouse($line->source_location_id, $issue->warehouse_id);
                $this->assertNotExpired($line, $issue->issue_date);

                [$baseQty] = $this->uomResolver->toBaseUnits($product, $line->uom_id, (float) $line->qty, 0.0);

                $balance = $this->balances->lockOrCreate($product->id, $issue->warehouse_id, $line->source_location_id, $line->batch_id);

                if ((float) $balance->qty_on_hand < $baseQty) {
                    $location = Location::query()->find($line->source_location_id);
                    $lot = $line->batch_id ? " (lot {$line->batch->batch_number})" : '';
                    throw ValidationException::withMessages([
                        'lines' => "Only {$balance->qty_on_hand} units of {$product->sku}{$lot} available at {$location?->code} — reduce quantity or choose another location/lot.",
                    ]);
                }

                $consumption = $this->costing->strategyFor($product)->costIssue($product->id, $issue->warehouse_id, $baseQty, $line->batch_id);

                $ledger = StockLedger::query()->create([
                    'product_id' => $product->id,
                    'warehouse_id' => $issue->warehouse_id,
                    'location_id' => $line->source_location_id,
                    'batch_id' => $line->batch_id,
                    'movement_type' => StockLedger::TYPE_ISSUE,
                    'qty' => -$baseQty,
                    'unit_cost' => $consumption['unit_cost'],
                    'total_value' => -$consumption['total_value'],
                    'subject_type' => 'inventory.goods_issues',
                    'subject_id' => (string) $issue->id,
                    'movement_date' => $issue->issue_date,
                    'created_by' => auth()->id(),
                ]);

                $this->balances->applyDelta($balance, -$baseQty);

                if ($product->tracking_mode === Product::TRACKING_SERIAL) {
                    $this->serials->issue($product->id, $line->serial_numbers ?? [], $issue->warehouse_id, $line->source_location_id, $ledger->id);
                }

                $rows[] = $ledger;
            }

            $issue->update(['status' => GoodsIssue::STATUS_POSTED, 'posted_at' => now()]);

            return $rows;
        });

        $this->dispatchAccountingEvents($ledgerRows);

        return $issue->refresh()->load('lines');
    }

    /** @param  list<StockLedger>  $ledgerRows */
    private function dispatchAccountingEvents(array $ledgerRows): void
    {
        $companyId = $this->companyResolver->resolve();
        if ($companyId === null) {
            return;
        }

        foreach ($ledgerRows as $ledger) {
            InventoryGoodsIssued::dispatch(
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

    private function assertDraft(GoodsIssue $issue): void
    {
        if ($issue->status !== GoodsIssue::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'This issue is already posted and can no longer be edited.']);
        }
    }

    /**
     * §3L: "expired batches with a blocking warning on Issue (overridable with a reason,
     * logged)". Expired is relative to the issue's own document date, not `today()` — a
     * backdated issue for a lot that expired since should evaluate against the date it
     * actually happened. The override reason IS the log — it lands on the line row, which
     * is itself part of the immutable posted document, same posture as everything else here.
     */
    private function assertNotExpired(GoodsIssueLine $line, \DateTimeInterface $issueDate): void
    {
        /** @var StockBatch|null $batch */
        $batch = $line->batch;
        if (! $batch || ! $batch->isExpiredAsOf($issueDate)) {
            return;
        }

        if (empty($line->expiry_override_reason)) {
            throw ValidationException::withMessages([
                'lines' => "Lot {$batch->batch_number} of {$line->product->sku} expired on {$batch->expiry_date->toDateString()} — override with a reason to issue it anyway.",
            ]);
        }
    }

    private function assertLocationInWarehouse(int $locationId, int $warehouseId): void
    {
        $belongs = Location::query()->where('id', $locationId)->where('warehouse_id', $warehouseId)->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['lines' => 'A line\'s source location does not belong to this issue\'s warehouse.']);
        }
    }

    /** §3M — see GoodsReceiptService::assertSerialCountMatchesQty(); same rule on the way out. @param  array<int, mixed>  $serialNumbers */
    private function assertSerialCountMatchesQty(Product $product, array $serialNumbers, float $qty): void
    {
        if (abs($qty - round($qty)) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — quantity must be a whole number."]);
        }
        if (abs(count($serialNumbers) - $qty) > self::EPSILON) {
            throw ValidationException::withMessages(['lines' => "{$product->sku} is serial-tracked — name exactly {$qty} serial(s) to issue, one per unit."]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function headerAttributes(array $data): array
    {
        return [
            'warehouse_id' => $data['warehouse_id'],
            'issue_date' => $data['issue_date'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'reason' => $data['reason'] ?? null,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(GoodsIssue $issue, array $lines): void
    {
        $issue->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['product_id']) || empty($line['qty'])) {
                continue;
            }

            GoodsIssueLine::query()->create([
                'goods_issue_id' => $issue->id,
                'product_id' => $line['product_id'],
                'batch_id' => $line['batch_id'] ?? null,
                'qty' => $line['qty'],
                'uom_id' => $line['uom_id'],
                'source_location_id' => $line['source_location_id'] ?? null,
                'expiry_override_reason' => $line['expiry_override_reason'] ?? null,
                'serial_numbers' => ! empty($line['serial_numbers']) ? array_values(array_filter($line['serial_numbers'])) : null,
            ]);
        }
    }
}
