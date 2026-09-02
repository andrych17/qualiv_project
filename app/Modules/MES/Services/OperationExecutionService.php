<?php

namespace App\Modules\MES\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\PP\Models\BomLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3G — Assembly Execution, Shop Floor Operation UI's write actions. Operation
 * status ("has this op started/paused/completed?") is a read model derived from the Production
 * Event Ledger (§3C, §5 Technical Notes: "this table is the single source other engines
 * derive from") — no separate `mes_operation_executions` table, matching the DDL's own choice
 * not to have one.
 */
class OperationExecutionService
{
    public function __construct(
        protected ProdEventService $events,
        protected MaterialConsumptionService $consumption,
        protected ProductionOutputService $output,
        protected SerialGenealogyService $genealogy,
        protected ReworkService $rework,
    ) {}

    /**
     * The next actionable operation for this order — the first (by `seq`, from the order's own
     * starting point) whose latest ledger event isn't `operation_completed`. Null once every op
     * from that point on is done.
     *
     * §3N: a rework child order (`ReworkService::isReworkOrder()`) starts at its routing's
     * rework-flagged op, not seq 1 — the material already passed through everything before that
     * once; re-running those steps isn't what "send to rework" means.
     */
    public function currentOp(ProdOrder $order): ?RoutingOp
    {
        if ($order->routing_id === null) {
            return null;
        }

        $ops = RoutingOp::query()->where('routing_id', $order->routing_id)->orderBy('seq')->get();
        $startSeq = $this->startSeqFor($order, $ops);

        foreach ($ops as $op) {
            if ($op->seq < $startSeq) {
                continue;
            }
            if ($this->latestEventType($order, $op) !== ProdEvent::TYPE_OPERATION_COMPLETED) {
                return $op;
            }
        }

        return null;
    }

    /** @return array<int, string|null> RoutingOp id => latest event type */
    public function statusesFor(ProdOrder $order): array
    {
        if ($order->routing_id === null) {
            return [];
        }

        $ops = RoutingOp::query()->where('routing_id', $order->routing_id)->orderBy('seq')->get();

        return $ops->mapWithKeys(fn (RoutingOp $op) => [$op->id => $this->latestEventType($order, $op)])->all();
    }

    public function start(ProdOrder $order, RoutingOp $op, int $userId): ProdOrder
    {
        $this->assertBelongsToOrder($order, $op);
        $this->assertPredecessorsCompleted($order, $op);

        $latest = $this->latestEventType($order, $op);
        if ($latest !== null) {
            throw ValidationException::withMessages(['operation' => "This operation is already {$latest}."]);
        }
        if (! in_array($order->status, [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS], true)) {
            throw ValidationException::withMessages(['status' => 'The order must be released before an operation can start.']);
        }

        return DB::transaction(function () use ($order, $op, $userId) {
            if ($order->status === ProdOrder::STATUS_RELEASED) {
                $order->update(['status' => ProdOrder::STATUS_IN_PROGRESS]);
            }
            if ($order->actual_start === null) {
                $order->update(['actual_start' => now()]);
            }

            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_STARTED, ['op_code' => $op->op_code], $userId, operationRef: $op->id);

            return $order->refresh();
        });
    }

    public function pause(ProdOrder $order, RoutingOp $op, int $userId): ProdOrder
    {
        $this->assertBelongsToOrder($order, $op);

        if ($this->latestEventType($order, $op) !== ProdEvent::TYPE_OPERATION_STARTED) {
            throw ValidationException::withMessages(['operation' => 'Only a started (not yet completed) operation can be paused.']);
        }

        return DB::transaction(function () use ($order, $op, $userId) {
            if ($order->status === ProdOrder::STATUS_IN_PROGRESS) {
                $order->update(['status' => ProdOrder::STATUS_PAUSED]);
            }

            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_PAUSED, ['op_code' => $op->op_code], $userId, operationRef: $op->id);

            return $order->refresh();
        });
    }

    /** Resuming a paused operation has no dedicated ledger event (§3C's `event_type` CHECK has none) — same "status change only" posture as `ProdOrderService::cancel()`. */
    public function resume(ProdOrder $order, RoutingOp $op): ProdOrder
    {
        $this->assertBelongsToOrder($order, $op);

        if ($this->latestEventType($order, $op) !== ProdEvent::TYPE_OPERATION_PAUSED) {
            throw ValidationException::withMessages(['operation' => 'Only a paused operation can be resumed.']);
        }
        if ($order->status === ProdOrder::STATUS_PAUSED) {
            $order->update(['status' => ProdOrder::STATUS_IN_PROGRESS]);
        }

        return $order->refresh();
    }

    /**
     * @param  array{qty_completed: float, qty_rejected?: float|null, location_id?: int|null, reject_reason_code?: string|null, lot_number?: string|null, serial_number?: string|null}  $data
     */
    public function complete(ProdOrder $order, RoutingOp $op, array $data, int $userId): ProdOrder
    {
        $this->assertBelongsToOrder($order, $op);

        $latest = $this->latestEventType($order, $op);
        if (! in_array($latest, [ProdEvent::TYPE_OPERATION_STARTED, ProdEvent::TYPE_OPERATION_PAUSED], true)) {
            throw ValidationException::withMessages(['operation' => 'Only a started operation can be completed.']);
        }

        $qtyCompleted = (float) $data['qty_completed'];
        $qtyRejected = (float) ($data['qty_rejected'] ?? 0);
        $isLastOp = (int) RoutingOp::query()->where('routing_id', $op->routing_id)->max('seq') === $op->seq;
        $finishedProduct = Product::query()->find($order->product_id);

        if ($isLastOp && $finishedProduct?->tracking_mode === Product::TRACKING_SERIAL && $qtyCompleted !== 1.0) {
            throw ValidationException::withMessages(['qty_completed' => "{$finishedProduct->sku} is serial-tracked — complete the final operation one unit (one serial) at a time."]);
        }

        return DB::transaction(function () use ($order, $op, $data, $userId, $qtyCompleted, $qtyRejected, $isLastOp) {
            $this->events->record($order->id, ProdEvent::TYPE_OPERATION_COMPLETED, ['op_code' => $op->op_code, 'qty_completed' => $qtyCompleted, 'qty_rejected' => $qtyRejected], $userId, operationRef: $op->id);

            if ($op->auto_issue_components) {
                $this->autoIssueComponents($order, $op, $qtyCompleted, $data['location_id'] ?? null, $userId);
            }

            if ($isLastOp) {
                $output = $this->output->record($order, [
                    'output_type' => 'finished',
                    'product_id' => $order->product_id,
                    'qty' => $qtyCompleted,
                    'uom_code' => $order->uom_code,
                    'location_id' => $data['location_id'] ?? null,
                    'lot_number' => $data['lot_number'] ?? null,
                    'serial_number' => $data['serial_number'] ?? null,
                    'operation_ref' => $op->id,
                ], $userId);

                if ($qtyRejected > 0) {
                    $this->output->record($order, [
                        'output_type' => 'waste',
                        'product_id' => $order->product_id,
                        'qty' => $qtyRejected,
                        'uom_code' => $order->uom_code,
                        'location_id' => $data['location_id'] ?? null,
                        'reason_code' => $data['reject_reason_code'] ?? 'shop_floor_reject',
                        'disposition' => 'scrap',
                        'operation_ref' => $op->id,
                    ], $userId);
                }

                if ($output->serial_id !== null) {
                    $this->genealogy->linkOrderConsumptionsToSerial($order, $output->serial_id, $op->id);
                }

                $order->update(['status' => ProdOrder::STATUS_COMPLETED, 'actual_end' => now()]);
            }

            return $order->refresh();
        });
    }

    /** §3G: "if the operation is configured to auto-issue components 1:1 with standard BOM usage" — full BOM lines, scaled to `qtyCompleted`. Only supports `tracking_mode = none` components; a batch/serial-tracked BOM line needs the operator to pick the specific lot/serial, so it's directed to the manual §3J panel instead. */
    private function autoIssueComponents(ProdOrder $order, RoutingOp $op, float $qtyCompleted, ?int $locationId, int $userId): void
    {
        if ($order->bom_id === null) {
            return;
        }
        if ($locationId === null) {
            throw ValidationException::withMessages(['location_id' => 'A location is required to auto-issue this operation\'s components.']);
        }

        $lines = BomLine::query()->where('bom_id', $order->bom_id)->with('component')->get();

        foreach ($lines as $line) {
            $component = $line->component;
            if ($component && $component->tracking_mode !== Product::TRACKING_NONE) {
                throw ValidationException::withMessages([
                    'location_id' => "{$component->sku} is lot/serial-tracked — auto-issue only supports untracked components; issue it manually via Material Consumption.",
                ]);
            }

            $this->consumption->record($order, [
                'material_product_id' => $line->component_product_id,
                'type' => 'issue',
                'qty' => (float) $line->qty_per_parent_unit * $qtyCompleted,
                'uom_code' => $line->uom_code,
                'location_id' => $locationId,
                'operation_ref' => $op->id,
            ], $userId);
        }
    }

    private function assertBelongsToOrder(ProdOrder $order, RoutingOp $op): void
    {
        if ($op->routing_id !== $order->routing_id) {
            throw ValidationException::withMessages(['operation' => 'This operation does not belong to the order\'s routing.']);
        }
    }

    /**
     * §3G: "an operation cannot start until its routing-defined predecessor is completed" —
     * strict sequential (no parallel-eligible flag in this build's schema). §3N: a rework
     * child's predecessors are only the ops from its own starting point onward (see
     * `startSeqFor()`) — the ops before that already ran once, on the parent order.
     */
    private function assertPredecessorsCompleted(ProdOrder $order, RoutingOp $op): void
    {
        $startSeq = $this->startSeqFor($order, null, $op->routing_id);
        $predecessors = RoutingOp::query()
            ->where('routing_id', $op->routing_id)
            ->where('seq', '<', $op->seq)
            ->where('seq', '>=', $startSeq)
            ->orderBy('seq')
            ->get();

        foreach ($predecessors as $predecessor) {
            if ($this->latestEventType($order, $predecessor) !== ProdEvent::TYPE_OPERATION_COMPLETED) {
                throw ValidationException::withMessages(['operation' => "Operation {$predecessor->op_code} must be completed first."]);
            }
        }
    }

    /**
     * §3N: the `seq` a rework child order's execution starts from — its routing's
     * rework-flagged op, or `0` (the very start) for every normal order.
     *
     * @param  Collection<int, RoutingOp>|null  $ops  pass this order's already-loaded ops to avoid a re-query; omit (with `$routingId`) to look one up.
     */
    private function startSeqFor(ProdOrder $order, ?Collection $ops = null, ?int $routingId = null): int
    {
        if (! $this->rework->isReworkOrder($order)) {
            return 0;
        }

        $reworkOp = $ops !== null
            ? $ops->firstWhere('is_rework_destination', true)
            : RoutingOp::query()->where('routing_id', $routingId)->where('is_rework_destination', true)->first();

        return $reworkOp?->seq ?? 0;
    }

    private function latestEventType(ProdOrder $order, RoutingOp $op): ?string
    {
        return ProdEvent::query()
            ->where('order_id', $order->id)
            ->where('operation_ref', $op->id)
            ->whereIn('event_type', [ProdEvent::TYPE_OPERATION_STARTED, ProdEvent::TYPE_OPERATION_PAUSED, ProdEvent::TYPE_OPERATION_COMPLETED])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('event_type');
    }
}
