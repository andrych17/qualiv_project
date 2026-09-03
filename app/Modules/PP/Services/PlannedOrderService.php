<?php

namespace App\Modules\PP\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Services\RequisitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PP_SPECS.md §3D Rules/Logic — Release, the Planning → Execution seam. Purchase-type release
 * is fully wired (Purchase module exists); production-type is guarded with a clear message
 * rather than a `MesService` stub, since MES isn't built yet (§7 Open Items) and nothing calls
 * such a stub; transfer-type is likewise guarded since this MRP engine never produces one
 * (no inter-warehouse demand source is wired in §3B).
 */
class PlannedOrderService
{
    public function __construct(protected RequisitionService $requisitions) {}

    public function release(PlannedOrder $order): PlannedOrder
    {
        if ($order->scenario_id !== null) {
            throw ValidationException::withMessages(['order' => 'A planned order inside a scenario can never be released — release is a baseline-only action.']);
        }
        if (! in_array($order->status, [PlannedOrder::STATUS_PLANNED, PlannedOrder::STATUS_FIRMED], true)) {
            throw ValidationException::withMessages(['order' => 'Only a planned or firmed order can be released.']);
        }

        return match ($order->order_type) {
            PlannedOrder::TYPE_PURCHASE => $this->releasePurchase($order),
            PlannedOrder::TYPE_PRODUCTION => throw ValidationException::withMessages([
                'order' => 'Releasing a production planned order requires the MES module, which is not built yet — see PP_SPECS.md §7 Open Items.',
            ]),
            default => throw ValidationException::withMessages([
                'order' => 'Transfer-type planned orders are not produced by this MRP engine and cannot be released.',
            ]),
        };
    }

    /**
     * PP_SPECS.md §3C — firming excludes an order from MrpService's regenerative delete (which
     * only touches `status = 'planned'` baseline rows), while keeping it eligible for later
     * release.
     */
    public function firm(PlannedOrder $order): PlannedOrder
    {
        if ($order->scenario_id !== null) {
            throw ValidationException::withMessages(['order' => 'A planned order inside a scenario can never be firmed.']);
        }
        if ($order->status !== PlannedOrder::STATUS_PLANNED) {
            throw ValidationException::withMessages(['order' => 'Only a planned order can be firmed.']);
        }

        $order->update(['status' => PlannedOrder::STATUS_FIRMED]);

        return $order->refresh();
    }

    public function unfirm(PlannedOrder $order): PlannedOrder
    {
        if ($order->status !== PlannedOrder::STATUS_FIRMED) {
            throw ValidationException::withMessages(['order' => 'Only a firmed order can be unfirmed.']);
        }

        $order->update(['status' => PlannedOrder::STATUS_PLANNED]);

        return $order->refresh();
    }

    private function releasePurchase(PlannedOrder $order): PlannedOrder
    {
        return DB::transaction(function () use ($order) {
            $product = Product::query()->find($order->product_id);

            $pr = $this->requisitions->create([
                'subject_type' => PlannedOrder::class,
                'subject_id' => $order->id,
                'needed_by' => $order->need_by_date->toDateString(),
                'lines' => [[
                    'description' => $product ? "{$product->sku} — {$product->name}" : "Product #{$order->product_id}",
                    'qty' => (float) $order->qty,
                    'estimated_unit_price' => 0,
                ]],
            ], auth()->id());

            $order->update([
                'status' => PlannedOrder::STATUS_RELEASED,
                'released_subject_type' => PurRequisitionHdr::class,
                'released_subject_id' => $pr->id,
                'released_at' => now(),
            ]);

            return $order->refresh();
        });
    }
}
