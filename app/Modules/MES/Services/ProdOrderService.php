<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\Routing;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use App\Modules\SysConfig\Services\ConfigSnumService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3A — Production Order lifecycle (`draft` → `released` → ... — §3G/§3I's shop
 * floor execution owns everything past `released`, not yet built). `bom_id`/`recipe_id`/
 * `routing_id` are resolved once here from the active master data (§3B: "resolved value
 * survives a later master-data edit"), never picked directly by the caller.
 */
class ProdOrderService
{
    public function __construct(
        protected ConfigSnumService $serials,
        protected ProdEventService $events,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): ProdOrder
    {
        return DB::transaction(function () use ($data) {
            $productId = (int) $data['product_id'];
            $model = $data['production_model'];

            $bomId = null;
            $recipeId = null;
            $routingId = null;

            if ($model === ProdOrder::MODEL_ASSEMBLY) {
                $bomId = Bom::query()->active()->where('product_id', $productId)->value('id');
                $routingId = Routing::query()->active()->where('product_id', $productId)->value('id');
            } else {
                $recipeId = Recipe::query()->active()->where('product_id', $productId)->value('id');
            }

            return ProdOrder::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'product_id' => $productId,
                'production_model' => $model,
                'bom_id' => $bomId,
                'recipe_id' => $recipeId,
                'routing_id' => $routingId,
                'qty' => $data['qty'],
                'uom_code' => $data['uom_code'] ?? null,
                'planned_start' => $data['planned_start'] ?? null,
                'planned_end' => $data['planned_end'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'line_area' => $data['line_area'] ?? null,
                'status' => ProdOrder::STATUS_DRAFT,
                // §3N rework hand-off (ReworkService) and, in future, MRP release are the only
                // callers that set these today — the manual Create form never does.
                'parent_order_id' => $data['parent_order_id'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ProdOrder $order, array $data): ProdOrder
    {
        if ($order->status !== ProdOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft production order can be edited.']);
        }

        $order->update([
            'qty' => $data['qty'],
            'uom_code' => $data['uom_code'] ?? null,
            'planned_start' => $data['planned_start'] ?? null,
            'planned_end' => $data['planned_end'] ?? null,
            'priority' => $data['priority'] ?? $order->priority,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'line_area' => $data['line_area'] ?? null,
        ]);

        return $order->refresh();
    }

    public function delete(ProdOrder $order): void
    {
        if ($order->status !== ProdOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft production order can be deleted.']);
        }

        $order->delete();
    }

    /**
     * §3A Rules/Logic: releasing fires `order_released` — the first ledger event, and the one
     * every later engine anchors to.
     *
     * ponytail: material reservation (§3A's "if Inventory is enabled, creates reservations via
     * InventoryService::checkAvailability()/reserve") is deferred — it needs PpService to
     * resolve BOM/Recipe lines into a component list, and PpService is explicitly not yet
     * implemented (MES_SPECS.md §7 Open Items). Wire it in once §3J Material Consumption and
     * PpService both exist, per the Build Order (§6) sequencing.
     */
    public function release(ProdOrder $order, int $userId): ProdOrder
    {
        if ($order->status !== ProdOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft production order can be released.']);
        }

        return DB::transaction(function () use ($order, $userId) {
            $order->update(['status' => ProdOrder::STATUS_RELEASED]);

            $this->events->record($order->id, ProdEvent::TYPE_ORDER_RELEASED, ['qty' => (float) $order->qty], $userId);

            return $order->refresh();
        });
    }

    /**
     * Cancel is a status change only — the event ledger's `event_type` CHECK (§3C) has no
     * "order cancelled" entry, only the execution actions §3G–§3M fire (not yet built), so
     * there is nothing to log here beyond the status column itself.
     */
    public function cancel(ProdOrder $order): ProdOrder
    {
        if (in_array($order->status, [ProdOrder::STATUS_COMPLETED, ProdOrder::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['status' => 'This order is already finished and cannot be cancelled.']);
        }

        $order->update(['status' => ProdOrder::STATUS_CANCELLED]);

        return $order->refresh();
    }

    private function nextOrderNumber(): string
    {
        $n = $this->serials->next('MES_MO_LASTID');

        return sprintf('WO-%06d', $n);
    }
}
