<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProductionOutput;
use Illuminate\Database\Eloquent\Builder;

/** MES_SPECS.md §3N — "Yield is computed, not stored: `good_output_qty / (good_output_qty + scrap_qty)` per order/batch, read from `mes_production_outputs`." No caching/materialization — recomputed on every read, same posture the spec's own text asks for. */
class YieldService
{
    /** @return array{good_qty: float, scrap_qty: float, yield_pct: float|null} */
    public function forOrder(int $orderId): array
    {
        return $this->compute(fn ($query) => $query->where('order_id', $orderId));
    }

    /** @param  list<int>  $operationRefs  batch_phase ids belonging to one `MesBatch` — production output rows don't carry a `batch_id` column directly, only `operation_ref` (§3J), so a batch's yield is scoped through its phases' ids. */
    public function forBatchPhaseIds(array $operationRefs): array
    {
        if ($operationRefs === []) {
            return ['good_qty' => 0.0, 'scrap_qty' => 0.0, 'yield_pct' => null];
        }

        return $this->compute(fn ($query) => $query->whereIn('operation_ref', $operationRefs));
    }

    /** @param  callable(Builder): void  $scope */
    private function compute(callable $scope): array
    {
        $query = ProductionOutput::query();
        $scope($query);

        $goodQty = (float) (clone $query)->where('output_type', '!=', ProductionOutput::TYPE_WASTE)->sum('qty');
        $scrapQty = (float) (clone $query)->where('output_type', ProductionOutput::TYPE_WASTE)->sum('qty');

        $denominator = $goodQty + $scrapQty;

        return [
            'good_qty' => $goodQty,
            'scrap_qty' => $scrapQty,
            'yield_pct' => $denominator > 0 ? round(($goodQty / $denominator) * 100, 2) : null,
        ];
    }
}
