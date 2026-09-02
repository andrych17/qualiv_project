<?php

namespace App\Modules\MES\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\MES\Models\BatchIngredient;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\PP\Models\RecipeIngredient;
use App\Modules\SysConfig\Services\ConfigSnumService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3I — Process Execution: Batch / Phase. Scaling a recipe's ingredients to this
 * batch's `planned_qty` (`qty_per_batch × planned_qty / recipe.batch_size`) bypasses the
 * not-yet-built `PpService::scaleRecipe()` (§3B/§7 Open Items) — the formula itself is exactly
 * what that service would do; this is a placement bypass, not a different calculation. Move the
 * math into `PpService` once it exists (tracked once, in `PP_SPECS.md` §7, not duplicated here).
 */
class BatchExecutionService
{
    public function __construct(
        protected ConfigSnumService $serials,
        protected ProdEventService $events,
        protected ProductionOutputService $output,
        protected YieldService $yield,
    ) {}

    /** @param  array{planned_qty?: float}  $data */
    public function create(ProdOrder $order, array $data): MesBatch
    {
        if ($order->production_model !== ProdOrder::MODEL_PROCESS) {
            throw ValidationException::withMessages(['production_model' => 'Only a process-model order can run a batch.']);
        }
        if (! in_array($order->status, [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS], true)) {
            throw ValidationException::withMessages(['status' => 'The order must be released before a batch can be created.']);
        }
        if ($order->recipe_id === null) {
            throw ValidationException::withMessages(['recipe_id' => 'This order has no resolved recipe.']);
        }

        $phaseCount = ProcessPhase::query()->where('recipe_id', $order->recipe_id)->count();
        if ($phaseCount === 0) {
            throw ValidationException::withMessages(['recipe_id' => 'This order\'s recipe has no process phases defined yet — add them under Process Phases first.']);
        }

        $plannedQty = (float) ($data['planned_qty'] ?? $order->qty);
        $recipe = $order->recipe()->first();
        $scaleFactor = $plannedQty / (float) $recipe->batch_size;

        return DB::transaction(function () use ($order, $plannedQty, $scaleFactor) {
            $batch = MesBatch::query()->create([
                'order_id' => $order->id,
                'batch_number' => $this->nextBatchNumber(),
                'recipe_id' => $order->recipe_id,
                'status' => MesBatch::STATUS_DRAFT,
                'planned_qty' => $plannedQty,
            ]);

            $ingredients = RecipeIngredient::query()->where('recipe_id', $order->recipe_id)->get();
            foreach ($ingredients as $ingredient) {
                BatchIngredient::query()->create([
                    'batch_id' => $batch->id,
                    'raw_material_product_id' => $ingredient->raw_material_product_id,
                    'resolved_qty' => (float) $ingredient->qty_per_batch * $scaleFactor,
                    'uom_code' => $ingredient->uom_code,
                ]);
            }

            $phases = ProcessPhase::query()->where('recipe_id', $order->recipe_id)->orderBy('seq')->get();
            foreach ($phases as $phase) {
                BatchPhase::query()->create([
                    'batch_id' => $batch->id,
                    'process_phase_id' => $phase->id,
                    'seq' => $phase->seq,
                    'status' => BatchPhase::STATUS_PENDING,
                    'machine_id' => null,
                ]);
            }

            return $batch->load(['ingredients', 'phases']);
        });
    }

    public function start(MesBatch $batch, int $userId): MesBatch
    {
        if ($batch->status !== MesBatch::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft batch can be started.']);
        }

        $firstPhase = $batch->phases()->orderBy('seq')->first();
        if (! $firstPhase) {
            throw ValidationException::withMessages(['status' => 'This batch has no phases to run.']);
        }

        return DB::transaction(function () use ($batch, $firstPhase, $userId) {
            $order = $batch->order;
            if ($order->status === ProdOrder::STATUS_RELEASED) {
                $order->update(['status' => ProdOrder::STATUS_IN_PROGRESS]);
            }
            if ($order->actual_start === null) {
                $order->update(['actual_start' => now()]);
            }

            $batch->update(['status' => MesBatch::STATUS_RUNNING]);
            $firstPhase->update(['status' => BatchPhase::STATUS_RUNNING, 'start_at' => now()]);

            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_STARTED, ['phase' => $firstPhase->processPhase->phase_name], $userId, operationRef: $firstPhase->id, batchId: $batch->id);

            return $batch->refresh();
        });
    }

    public function pause(MesBatch $batch, int $userId): MesBatch
    {
        $runningPhase = $batch->phases()->where('status', BatchPhase::STATUS_RUNNING)->first();
        if ($batch->status !== MesBatch::STATUS_RUNNING || ! $runningPhase) {
            throw ValidationException::withMessages(['status' => 'Only a running batch can be paused.']);
        }

        return DB::transaction(function () use ($batch, $runningPhase, $userId) {
            $batch->update(['status' => MesBatch::STATUS_PAUSED]);
            $runningPhase->update(['status' => BatchPhase::STATUS_PAUSED]);

            $order = $batch->order;
            if ($order->status === ProdOrder::STATUS_IN_PROGRESS) {
                $order->update(['status' => ProdOrder::STATUS_PAUSED]);
            }

            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_PAUSED, ['phase' => $runningPhase->processPhase->phase_name], $userId, operationRef: $runningPhase->id, batchId: $batch->id);

            return $batch->refresh();
        });
    }

    /** No dedicated ledger event for resume (§3C's `event_type` CHECK has none) — same "status change only" posture as `OperationExecutionService::resume()`. */
    public function resume(MesBatch $batch): MesBatch
    {
        $pausedPhase = $batch->phases()->where('status', BatchPhase::STATUS_PAUSED)->first();
        if ($batch->status !== MesBatch::STATUS_PAUSED || ! $pausedPhase) {
            throw ValidationException::withMessages(['status' => 'Only a paused batch can be resumed.']);
        }

        $batch->update(['status' => MesBatch::STATUS_RUNNING]);
        $pausedPhase->update(['status' => BatchPhase::STATUS_RUNNING]);

        $order = $batch->order;
        if ($order->status === ProdOrder::STATUS_PAUSED) {
            $order->update(['status' => ProdOrder::STATUS_IN_PROGRESS]);
        }

        return $batch->refresh();
    }

    /**
     * §3I: "A reading outside [min, max] writes a `parameter_recorded` event flagged
     * `out_of_range`" — QC-hold-on-critical-parameter is skipped (§3L isn't built; no
     * `quality_critical` column exists on `mes_process_parameters` to gate it on).
     *
     * @param  list<array{process_parameter_id: int, value: float}>  $readings
     */
    public function completePhase(MesBatch $batch, BatchPhase $phase, array $readings, int $userId, ?int $locationId = null): MesBatch
    {
        if ($phase->batch_id !== $batch->id) {
            throw ValidationException::withMessages(['phase' => 'This phase does not belong to the batch.']);
        }
        if (! in_array($phase->status, [BatchPhase::STATUS_RUNNING, BatchPhase::STATUS_PAUSED], true)) {
            throw ValidationException::withMessages(['phase' => 'Only a running (or paused) phase can be completed.']);
        }

        $isLastPhase = (int) $batch->phases()->max('seq') === $phase->seq;
        if ($isLastPhase) {
            $finishedProduct = $batch->order->product()->first();
            if ($finishedProduct?->tracking_mode === Product::TRACKING_SERIAL && (float) $batch->planned_qty !== 1.0) {
                throw ValidationException::withMessages(['planned_qty' => "{$finishedProduct->sku} is serial-tracked — a batch producing more than one unit can't post a single serial-tracked output row."]);
            }
        }

        return DB::transaction(function () use ($batch, $phase, $readings, $userId, $locationId) {
            $order = $batch->order;

            foreach ($readings as $reading) {
                $parameter = ProcessParameter::query()->findOrFail($reading['process_parameter_id']);
                $value = (float) $reading['value'];
                $outOfRange = ($parameter->min_value !== null && $value < (float) $parameter->min_value)
                    || ($parameter->max_value !== null && $value > (float) $parameter->max_value);

                BatchParameterReading::query()->create([
                    'batch_phase_id' => $phase->id,
                    'process_parameter_id' => $parameter->id,
                    'value' => $value,
                    'recorded_at' => now(),
                    'recorded_by' => $userId,
                ]);

                $this->events->record(
                    $order->id,
                    ProdEvent::TYPE_PARAMETER_RECORDED,
                    ['parameter_code' => $parameter->parameter_code, 'value' => $value, 'out_of_range' => $outOfRange],
                    $userId,
                    operationRef: $phase->id,
                    batchId: $batch->id,
                );
            }

            $phase->update(['status' => BatchPhase::STATUS_COMPLETED, 'end_at' => now()]);
            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_COMPLETED, ['phase' => $phase->processPhase->phase_name], $userId, operationRef: $phase->id, batchId: $batch->id);

            $nextPhase = $batch->phases()->where('seq', '>', $phase->seq)->orderBy('seq')->first();

            if ($nextPhase) {
                $nextPhase->update(['status' => BatchPhase::STATUS_RUNNING, 'start_at' => now()]);
                $this->events->record($order->id, ProdEvent::TYPE_OPERATION_STARTED, ['phase' => $nextPhase->processPhase->phase_name], $userId, operationRef: $nextPhase->id, batchId: $batch->id);
            } else {
                $this->output->record($order, [
                    'output_type' => 'finished',
                    'product_id' => $order->product_id,
                    'qty' => (float) $batch->planned_qty,
                    'uom_code' => $order->uom_code,
                    'location_id' => $locationId,
                    'operation_ref' => $phase->id,
                ], $userId);

                // §3N: "Yield is computed... per order/batch" — `mes_production_outputs` has no
                // `batch_id` column, only `operation_ref` (§3J), so a batch's own output rows
                // are found through its phases' ids. Persisted onto `actual_yield_pct` here (the
                // one snapshot column §3I's own DDL provides) rather than left purely derived,
                // since a batch is closed at this point and its yield won't change again.
                $phaseIds = $batch->phases()->pluck('id')->all();
                $yield = $this->yield->forBatchPhaseIds($phaseIds);

                $batch->update(['status' => MesBatch::STATUS_COMPLETED, 'actual_yield_pct' => $yield['yield_pct']]);
                $order->update(['status' => ProdOrder::STATUS_COMPLETED, 'actual_end' => now()]);
            }

            return $batch->refresh();
        });
    }

    private function nextBatchNumber(): string
    {
        $n = $this->serials->next('MES_BATCH_LASTID');

        return sprintf('B-%06d', $n);
    }
}
