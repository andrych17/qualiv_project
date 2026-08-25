<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\InventoryGlMapping;
use App\Modules\Accounting\Models\InventoryGlPosting;
use App\Modules\Accounting\Models\InventoryPostingFailure;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3H — posts the financial side of an Inventory movement, using only the value Inventory
 * has already computed (never a locally recalculated figure — this engine holds zero
 * costing logic, per spec). Each public method mirrors one of the three
 * App\Modules\Accounting\Events\Inventory* events; PostGoodsReceivedToGl/PostGoodsIssuedToGl/
 * PostStockAdjustmentToGl each call the matching method.
 *
 * Every method is idempotent on (subject_type, subject_id) — a replayed event (queue retry,
 * or a manual Retry from the failure queue) is a safe no-op if already posted. A movement
 * with no GL mapping, an incomplete mapping, or no fiscal period covering its date "fails
 * loudly and queues for review rather than posting to a suspense account silently" (spec
 * rule) — it records an InventoryPostingFailure row and returns normally, it never throws.
 */
class InventoryGlPostingService
{
    private const EVENT_GOODS_RECEIVED = 'goods_received';

    private const EVENT_GOODS_ISSUED = 'goods_issued';

    private const EVENT_STOCK_ADJUSTED = 'stock_adjusted';

    public function __construct(private readonly JournalService $journals) {}

    public function postGoodsReceived(InventoryGoodsReceived $event): void
    {
        if ($this->alreadyPosted($event->subjectType, $event->subjectId)) {
            return;
        }

        $mapping = $this->resolveMapping($event->companyId, $event->inventoryItemId);
        if (! $mapping) {
            $this->recordFailure(self::EVENT_GOODS_RECEIVED, $event, 'No GL mapping found for this item or its category.');

            return;
        }
        if (! $mapping->grni_account_id) {
            $this->recordFailure(self::EVENT_GOODS_RECEIVED, $event, 'Mapping exists but has no GRNI/accrual account configured for goods receipts.');

            return;
        }

        $period = $this->resolveFiscalPeriod($event->companyId, $event->movementDate);
        if (! $period) {
            $this->recordFailure(self::EVENT_GOODS_RECEIVED, $event, 'No fiscal period covers this movement date.');

            return;
        }

        $amount = round($event->totalValue, 2);
        if (abs($amount) < 0.005) {
            return; // zero-value movement — nothing to post, safe to skip (naturally idempotent, no row needed)
        }

        $company = Company::query()->findOrFail($event->companyId);
        $journal = $this->journals->create([
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'journal_date' => $event->movementDate,
            'currency_code' => $company->base_currency,
            'memo' => $event->memo ?? "Goods received — item #{$event->inventoryItemId}, qty {$event->quantity}",
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], [
            ['account_id' => $mapping->inventory_asset_account_id, 'debit' => $amount],
            ['account_id' => $mapping->grni_account_id, 'credit' => $amount],
        ], null, 'inventory');

        $journal = $this->journals->post($journal, null);

        $this->finalizePosting(self::EVENT_GOODS_RECEIVED, $event, $journal);
    }

    public function postGoodsIssued(InventoryGoodsIssued $event): void
    {
        if ($this->alreadyPosted($event->subjectType, $event->subjectId)) {
            return;
        }

        $mapping = $this->resolveMapping($event->companyId, $event->inventoryItemId);
        if (! $mapping) {
            $this->recordFailure(self::EVENT_GOODS_ISSUED, $event, 'No GL mapping found for this item or its category.');

            return;
        }
        if (! $mapping->cogs_account_id) {
            $this->recordFailure(self::EVENT_GOODS_ISSUED, $event, 'Mapping exists but has no COGS account configured for goods issues.');

            return;
        }

        $period = $this->resolveFiscalPeriod($event->companyId, $event->movementDate);
        if (! $period) {
            $this->recordFailure(self::EVENT_GOODS_ISSUED, $event, 'No fiscal period covers this movement date.');

            return;
        }

        $amount = round($event->totalValue, 2);
        if (abs($amount) < 0.005) {
            return;
        }

        $company = Company::query()->findOrFail($event->companyId);
        $journal = $this->journals->create([
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'journal_date' => $event->movementDate,
            'currency_code' => $company->base_currency,
            'memo' => $event->memo ?? "Goods issued — item #{$event->inventoryItemId}, qty {$event->quantity}",
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], [
            ['account_id' => $mapping->cogs_account_id, 'debit' => $amount],
            ['account_id' => $mapping->inventory_asset_account_id, 'credit' => $amount],
        ], null, 'inventory');

        $journal = $this->journals->post($journal, null);

        $this->finalizePosting(self::EVENT_GOODS_ISSUED, $event, $journal);
    }

    /** totalValue is signed: positive = write-up (debit inventory-asset), negative = write-down (debit adjustment). */
    public function postStockAdjusted(InventoryStockAdjusted $event): void
    {
        if ($this->alreadyPosted($event->subjectType, $event->subjectId)) {
            return;
        }

        $mapping = $this->resolveMapping($event->companyId, $event->inventoryItemId);
        if (! $mapping) {
            $this->recordFailure(self::EVENT_STOCK_ADJUSTED, $event, 'No GL mapping found for this item or its category.');

            return;
        }
        if (! $mapping->adjustment_account_id) {
            $this->recordFailure(self::EVENT_STOCK_ADJUSTED, $event, 'Mapping exists but has no adjustment/write-off account configured.');

            return;
        }

        $period = $this->resolveFiscalPeriod($event->companyId, $event->movementDate);
        if (! $period) {
            $this->recordFailure(self::EVENT_STOCK_ADJUSTED, $event, 'No fiscal period covers this movement date.');

            return;
        }

        $amount = round(abs($event->totalValue), 2);
        if ($amount < 0.005) {
            return;
        }
        $isWriteUp = $event->totalValue >= 0;

        $company = Company::query()->findOrFail($event->companyId);
        $journal = $this->journals->create([
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'journal_date' => $event->movementDate,
            'currency_code' => $company->base_currency,
            'memo' => $event->memo ?? "Stock adjustment — item #{$event->inventoryItemId}, qty {$event->quantity}",
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], [
            ['account_id' => $mapping->inventory_asset_account_id, 'debit' => $isWriteUp ? $amount : 0, 'credit' => $isWriteUp ? 0 : $amount],
            ['account_id' => $mapping->adjustment_account_id, 'debit' => $isWriteUp ? 0 : $amount, 'credit' => $isWriteUp ? $amount : 0],
        ], null, 'inventory');

        $journal = $this->journals->post($journal, null);

        $this->finalizePosting(self::EVENT_STOCK_ADJUSTED, $event, $journal);
    }

    /** Rebuilds the matching event from a failure row's stored payload and re-attempts posting — a no-op if it was already resolved by a concurrent retry. */
    public function retry(InventoryPostingFailure $failure): void
    {
        if ($failure->status === InventoryPostingFailure::STATUS_RESOLVED) {
            throw ValidationException::withMessages(['failure' => 'This item was already resolved.']);
        }

        $p = $failure->payload;

        match ($failure->event_type) {
            self::EVENT_GOODS_RECEIVED => $this->postGoodsReceived(new InventoryGoodsReceived(...$p)),
            self::EVENT_GOODS_ISSUED => $this->postGoodsIssued(new InventoryGoodsIssued(...$p)),
            self::EVENT_STOCK_ADJUSTED => $this->postStockAdjusted(new InventoryStockAdjusted(...$p)),
            default => throw new \LogicException("Unknown inventory event_type: {$failure->event_type}"),
        };
    }

    private function alreadyPosted(string $subjectType, string $subjectId): bool
    {
        return InventoryGlPosting::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->exists();
    }

    /**
     * Item-level mapping wins; falls back to the item's category mapping. `$inventoryItemId`
     * is `App\Modules\Inventory\Models\Product::id` (INVENTORY.products) — the real Inventory
     * engine's identity, not the legacy public-schema `inventory_items` demo table (CLAUDE.md
     * §7A). `inventory_gl_mappings.inventory_category_id` correspondingly means
     * `INVENTORY.product_categories.id` going forward.
     */
    private function resolveMapping(int $companyId, int $inventoryItemId): ?InventoryGlMapping
    {
        $mapping = InventoryGlMapping::query()
            ->where('company_id', $companyId)
            ->where('inventory_item_id', $inventoryItemId)
            ->first();
        if ($mapping) {
            return $mapping;
        }

        $categoryId = Product::query()->find($inventoryItemId)?->category_id;
        if (! $categoryId) {
            return null;
        }

        return InventoryGlMapping::query()
            ->where('company_id', $companyId)
            ->where('inventory_category_id', $categoryId)
            ->first();
    }

    private function resolveFiscalPeriod(int $companyId, string $movementDate): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $movementDate)
            ->whereDate('end_date', '>=', $movementDate)
            ->first();
    }

    /** @param  InventoryGoodsReceived|InventoryGoodsIssued|InventoryStockAdjusted  $event */
    private function recordFailure(string $eventType, $event, string $reason): void
    {
        $failure = InventoryPostingFailure::query()->firstOrNew(['subject_type' => $event->subjectType, 'subject_id' => $event->subjectId]);
        if (! $failure->exists) {
            $failure->uuid = (string) Str::uuid();
        }
        $failure->fill([
            'company_id' => $event->companyId,
            'event_type' => $eventType,
            'inventory_item_id' => $event->inventoryItemId,
            'payload' => $this->payloadOf($event),
            'reason' => $reason,
            'status' => InventoryPostingFailure::STATUS_PENDING,
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
        $failure->save();
    }

    /** @param  InventoryGoodsReceived|InventoryGoodsIssued|InventoryStockAdjusted  $event */
    private function finalizePosting(string $eventType, $event, GlJournal $journal): void
    {
        InventoryGlPosting::query()->create([
            'company_id' => $event->companyId,
            'event_type' => $eventType,
            'inventory_item_id' => $event->inventoryItemId,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'journal_id' => $journal->id,
        ]);

        InventoryPostingFailure::query()
            ->where('subject_type', $event->subjectType)
            ->where('subject_id', $event->subjectId)
            ->where('status', InventoryPostingFailure::STATUS_PENDING)
            ->update(['status' => InventoryPostingFailure::STATUS_RESOLVED, 'resolved_at' => now()]);
    }

    /** @param  InventoryGoodsReceived|InventoryGoodsIssued|InventoryStockAdjusted  $event */
    private function payloadOf($event): array
    {
        return [
            'companyId' => $event->companyId,
            'inventoryItemId' => $event->inventoryItemId,
            'quantity' => $event->quantity,
            'unitCost' => $event->unitCost,
            'totalValue' => $event->totalValue,
            'movementDate' => $event->movementDate,
            'subjectType' => $event->subjectType,
            'subjectId' => $event->subjectId,
            'memo' => $event->memo,
        ];
    }
}
