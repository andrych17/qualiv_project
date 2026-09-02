<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\RoutingOp;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3N — "Rework: `disposition = rework` routes the quantity to a rework
 * operation/phase (reuses §3G/§3I's execution engine against a rework-flagged routing/recipe
 * step)." Implemented as a child Production Order — `parent_order_id`/`source_type`/`source_id`
 * were already on `mes_prod_order_hdrs` for exactly this ("sub-assemblies/intermediate
 * batches", §3A) — pointed at the triggering waste row via `SOURCE_TYPE`.
 *
 * Assembly (routing ops) only: a process-model equivalent needs the same
 * `is_rework_destination` flag on `mes_process_phases` plus a way to start a batch mid-sequence
 * (`BatchExecutionService::create()` always materializes phase 1 onward), a bigger change than
 * this section asks for on its own.
 *
 * The spec's closing step — "re-inspected via §3L → pass posts as finished, fail posts as
 * scrap" — isn't implemented: §3L (Quality Control) isn't built. The rework child order's final
 * operation posts `finished` output the same way any order's does; there is no automated
 * pass/fail gate on it yet.
 */
class ReworkService
{
    public const SOURCE_TYPE = 'mes.mes_production_outputs';

    public function __construct(protected ProdOrderService $orders) {}

    public function sendToRework(ProductionOutput $output, int $userId): ProdOrder
    {
        if ($output->output_type !== ProductionOutput::TYPE_WASTE || $output->disposition !== ProductionOutput::DISPOSITION_REWORK) {
            throw ValidationException::withMessages(['disposition' => 'Only a waste output flagged for rework can be sent to rework.']);
        }

        $alreadySent = ProdOrder::query()->where('source_type', self::SOURCE_TYPE)->where('source_id', $output->id)->exists();
        if ($alreadySent) {
            throw ValidationException::withMessages(['output' => 'This output has already been sent to rework.']);
        }

        $order = $output->order()->firstOrFail();

        if ($order->production_model !== ProdOrder::MODEL_ASSEMBLY) {
            throw ValidationException::withMessages(['production_model' => 'Rework hand-off supports assembly-model orders only in this build.']);
        }
        if ($order->routing_id === null || ! RoutingOp::query()->where('routing_id', $order->routing_id)->where('is_rework_destination', true)->exists()) {
            throw ValidationException::withMessages(['routing_id' => 'This order\'s routing has no operation flagged as a rework destination — flag one under Routings first.']);
        }

        return DB::transaction(function () use ($order, $output, $userId) {
            $child = $this->orders->create([
                'product_id' => $order->product_id,
                'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'qty' => (float) $output->qty,
                'uom_code' => $output->uom_code,
                'warehouse_id' => $order->warehouse_id,
                'priority' => $order->priority,
                'parent_order_id' => $order->id,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $output->id,
            ]);

            return $this->orders->release($child, $userId);
        });
    }

    public function isReworkOrder(ProdOrder $order): bool
    {
        return $order->source_type === self::SOURCE_TYPE && $order->parent_order_id !== null;
    }
}
