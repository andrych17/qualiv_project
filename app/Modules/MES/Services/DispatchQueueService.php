<?php

namespace App\Modules\MES\Services;

use App\Modules\HCM\Models\ShiftAssignment;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\PP\Models\BomLine;
use App\Modules\PP\Models\RecipeIngredient;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3Q — MES Scheduling. Per `PP_SPECS.md` §5's boundary note, PP's own §3H already
 * proposes the schedule (finite/infinite toggle, sequencing strategy, changeover optimization,
 * campaign grouping, Gantt) against *draft* `pp_schedule_ops`; this class is deliberately
 * narrower — it "re-sequences only the live dispatch queue in front of an operator as floor
 * conditions change," over already-*released* `mes_prod_order_hdrs` rows. No new storage (same
 * "pure read model" posture §3K/§3O/§3T already use in this spec) — `priority` is the one
 * existing, writable lever a supervisor has to react to a floor condition (material just arrived,
 * a machine just came back up): `promote()` bumps it to `urgent` and the next `forWorkCenter()`
 * read reflects it immediately.
 */
class DispatchQueueService
{
    private const PRIORITY_RANK = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];

    public function __construct(
        protected OperationExecutionService $operations,
        protected InventoryService $inventory,
        protected MesAuditLogger $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forWorkCenter(?int $workCenterId): array
    {
        $orders = ProdOrder::query()
            ->whereIn('status', [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED])
            ->with(['product:id,sku,name', 'bom', 'recipe', 'batches.phases.processPhase.workCenter'])
            ->get();

        $rows = $orders
            ->map(fn (ProdOrder $order) => $this->rowFor($order))
            ->when($workCenterId !== null, fn (Collection $rows) => $rows->filter(fn ($row) => $row['work_center_id'] === $workCenterId))
            ->sort(fn ($a, $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        $previousProductId = null;
        $result = [];
        foreach ($rows as $row) {
            $row['same_campaign_as_previous'] = $previousProductId !== null && $previousProductId === $row['product_id'];
            $previousProductId = $row['product_id'];
            unset($row['product_id']);
            $result[] = $row;
        }

        return $result;
    }

    public function promote(ProdOrder $order, int $userId): ProdOrder
    {
        if (! in_array($order->status, [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a released, in-progress, or paused order can be promoted in the dispatch queue.']);
        }

        $before = ['priority' => $order->priority];
        $order->update(['priority' => 'urgent']);
        $this->audit->log('mes.mes_prod_order_hdrs', $order->id, 'dispatch_promoted', $before, ['priority' => 'urgent'], $userId);

        return $order->refresh();
    }

    /** Whether any shift covering the current time is assigned today — tenant-wide (`shift_assignments` carries no work-center link in this build), so this is "is a shift in session at all," not "is *this* order's work center staffed." */
    public function shiftInSession(): bool
    {
        $now = now()->format('H:i:s');

        return ShiftAssignment::query()
            ->whereDate('work_date', now()->toDateString())
            ->whereHas('shift', fn ($q) => $q->where('is_active', true)->where('start_time', '<=', $now)->where('end_time', '>=', $now))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function rowFor(ProdOrder $order): array
    {
        $step = $order->production_model === ProdOrder::MODEL_ASSEMBLY
            ? $this->currentAssemblyStep($order)
            : $this->currentProcessStep($order);

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'product_id' => $order->product_id,
            'product_sku' => $order->product?->sku,
            'product_name' => $order->product?->name,
            'production_model' => $order->production_model,
            'qty' => (float) $order->qty,
            'priority' => $order->priority,
            'due_date' => $order->planned_end?->toDateTimeString(),
            'current_step_code' => $step['code'],
            'current_step_name' => $step['name'],
            'work_center_id' => $step['work_center_id'],
            'work_center_code' => $step['work_center_code'],
            'setup_minutes' => $step['setup_minutes'],
            'material_status' => $this->materialStatus($order),
        ];
    }

    /** @return array{code: string|null, name: string|null, work_center_id: int|null, work_center_code: string|null, setup_minutes: int|null} */
    private function currentAssemblyStep(ProdOrder $order): array
    {
        $op = $this->operations->currentOp($order);
        if ($op === null) {
            return ['code' => null, 'name' => null, 'work_center_id' => null, 'work_center_code' => null, 'setup_minutes' => null];
        }

        return [
            'code' => $op->op_code,
            'name' => $op->op_name,
            'work_center_id' => $op->work_center_id,
            'work_center_code' => $op->workCenter?->code,
            'setup_minutes' => $op->setup_time_minutes,
        ];
    }

    /** @return array{code: string|null, name: string|null, work_center_id: int|null, work_center_code: string|null, setup_minutes: int|null} */
    private function currentProcessStep(ProdOrder $order): array
    {
        $batch = $order->batches->first();
        $phase = $batch?->phases
            ->sortBy('seq')
            ->first(fn (BatchPhase $p) => $p->status !== BatchPhase::STATUS_COMPLETED);

        if ($phase === null) {
            return ['code' => null, 'name' => null, 'work_center_id' => null, 'work_center_code' => null, 'setup_minutes' => null];
        }

        return [
            'code' => "PHASE-{$phase->seq}",
            'name' => $phase->processPhase?->phase_name,
            'work_center_id' => $phase->processPhase?->work_center_id,
            'work_center_code' => $phase->processPhase?->workCenter?->code,
            // Process phases carry no setup/run split in this build's schema (§3F) — only a
            // single standard_duration_minutes, so there is no distinct "setup" figure to show.
            'setup_minutes' => null,
        ];
    }

    /**
     * `warehouse_id` unset (nullable on the order) reads as "unknown," never as "available."
     * Public: also called by `AndonService` (§3R) to detect the `material_shortage` alert
     * condition — same check, not duplicated.
     */
    public function materialStatus(ProdOrder $order): string
    {
        if ($order->warehouse_id === null) {
            return 'unknown';
        }

        $requirements = $order->production_model === ProdOrder::MODEL_ASSEMBLY
            ? $this->assemblyRequirements($order)
            : $this->processRequirements($order);

        if ($requirements->isEmpty()) {
            return 'unknown';
        }

        foreach ($requirements as $productId => $requiredQty) {
            if ($this->inventory->checkAvailability((int) $productId, $order->warehouse_id) < $requiredQty) {
                return 'shortage';
            }
        }

        return 'available';
    }

    /** @return Collection<int, float> component_product_id => required qty */
    private function assemblyRequirements(ProdOrder $order): Collection
    {
        if ($order->bom_id === null) {
            return collect();
        }

        return BomLine::query()->where('bom_id', $order->bom_id)->get()
            ->reduce(function (Collection $carry, BomLine $line) use ($order) {
                $required = (float) $line->qty_per_parent_unit * (float) $order->qty;

                return $carry->put($line->component_product_id, ($carry->get($line->component_product_id, 0.0)) + $required);
            }, collect());
    }

    /** @return Collection<int, float> raw_material_product_id => required qty */
    private function processRequirements(ProdOrder $order): Collection
    {
        if ($order->recipe_id === null) {
            return collect();
        }

        $batchSize = (float) ($order->recipe?->batch_size ?? 0);
        $scale = $batchSize > 0 ? ((float) $order->qty / $batchSize) : 1.0;

        return RecipeIngredient::query()->where('recipe_id', $order->recipe_id)->get()
            ->reduce(function (Collection $carry, RecipeIngredient $ingredient) use ($scale) {
                $required = (float) $ingredient->qty_per_batch * $scale;

                return $carry->put($ingredient->raw_material_product_id, ($carry->get($ingredient->raw_material_product_id, 0.0)) + $required);
            }, collect());
    }

    /** @param  array<string, mixed>  $row
     * @return array<int, int|float>
     */
    private function sortKey(array $row): array
    {
        return [
            self::PRIORITY_RANK[$row['priority']] ?? 2,
            $row['due_date'] !== null ? strtotime($row['due_date']) : PHP_INT_MAX,
        ];
    }
}
