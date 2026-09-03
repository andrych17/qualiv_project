<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\ScheduleOp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PP_SPECS.md §3H — the Detailed Scheduling board's write side: propose (draft), lock in
 * (commit), and hand off to execution (release), plus the "movable" actions the spec names
 * (change resource/date/sequence via update(), split operation, merge batches).
 *
 * Finite capacity is enforced PP-locally: `pp_schedule_ops.resource_type` is restricted to
 * `mes_*` values (§3H's own DDL — no `pp_resource` option), so there is no real MES resource
 * record yet to check against Schedule's `AvailabilityService`. Conflict-checking instead
 * compares against this table's own committed/released rows for the same (resource_type,
 * resource_ref_id) — real data, no external dependency. A draft is exploratory and never
 * conflicts with anything; the invariant applies only once a row becomes committed/released.
 */
class ScheduleOpService
{
    public function __construct(
        protected PlannedOrderService $orders,
        protected SchedulingRuleService $rules,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): ScheduleOp
    {
        $this->guardProductionOrder((int) $data['planned_order_id']);

        $op = new ScheduleOp([
            'planned_order_id' => $data['planned_order_id'],
            'seq' => $data['seq'] ?? 1,
            'resource_type' => $data['resource_type'] ?? null,
            'resource_ref_id' => $data['resource_ref_id'] ?? null,
            'planned_start' => $data['planned_start'],
            'planned_end' => $data['planned_end'],
            'status' => $data['status'] ?? ScheduleOp::STATUS_DRAFT,
        ]);
        $this->guardNoConflict($op);
        $op->save();

        return $op->refresh();
    }

    /** Resource/date/sequence changes only — planned_order_id and status are fixed here; status moves through commit()/release(). */
    public function update(ScheduleOp $op, array $data): ScheduleOp
    {
        $op->fill([
            'seq' => $data['seq'] ?? $op->seq,
            'resource_type' => $data['resource_type'] ?? null,
            'resource_ref_id' => $data['resource_ref_id'] ?? null,
            'planned_start' => $data['planned_start'],
            'planned_end' => $data['planned_end'],
        ]);
        $this->guardNoConflict($op);
        $op->save();

        return $op->refresh();
    }

    public function delete(ScheduleOp $op): void
    {
        $op->delete();
    }

    public function commit(ScheduleOp $op): ScheduleOp
    {
        if ($op->status !== ScheduleOp::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft operation can be committed.']);
        }

        $op->status = ScheduleOp::STATUS_COMMITTED;
        $this->guardNoConflict($op);
        $op->save();

        return $op->refresh();
    }

    /**
     * PP_SPECS.md §3H Rules/Logic — "releasing a scheduled operation is what actually creates or
     * updates the corresponding MES.mes_prod_order_hdrs via §3D's release action." Delegates to
     * PlannedOrderService::release() rather than duplicating its guards; that call always throws
     * for a production order today (MES isn't built — §7 Open Items), same as
     * PlannedOrderController's own Release button.
     */
    public function release(ScheduleOp $op): ScheduleOp
    {
        if ($op->status !== ScheduleOp::STATUS_COMMITTED) {
            throw ValidationException::withMessages(['status' => 'Only a committed operation can be released.']);
        }

        $order = $op->plannedOrder;
        if ($order->status !== PlannedOrder::STATUS_RELEASED) {
            $this->orders->release($order);
        }

        $op->update(['status' => ScheduleOp::STATUS_RELEASED]);

        return $op->refresh();
    }

    /**
     * §3H "split operation" — divides one op's time window into two contiguous rows at $splitAt.
     *
     * @return array{0: ScheduleOp, 1: ScheduleOp}
     */
    public function split(ScheduleOp $op, Carbon $splitAt): array
    {
        if ($splitAt->lte($op->planned_start) || $splitAt->gte($op->planned_end)) {
            throw ValidationException::withMessages(['split_at' => 'Split point must fall strictly inside the operation window.']);
        }
        if ($op->status === ScheduleOp::STATUS_RELEASED) {
            throw ValidationException::withMessages(['status' => 'A released operation can no longer be split.']);
        }

        return DB::transaction(function () use ($op, $splitAt) {
            $originalEnd = $op->planned_end;
            $op->update(['planned_end' => $splitAt]);

            $second = ScheduleOp::query()->create([
                'planned_order_id' => $op->planned_order_id,
                'seq' => $op->seq,
                'resource_type' => $op->resource_type,
                'resource_ref_id' => $op->resource_ref_id,
                'planned_start' => $splitAt,
                'planned_end' => $originalEnd,
                'status' => $op->status,
            ]);

            return [$op->refresh(), $second];
        });
    }

    /** §3K "merge batches" (process) — combine two contiguous/overlapping ops on the same order+resource into one. */
    public function merge(ScheduleOp $a, ScheduleOp $b): ScheduleOp
    {
        if ($a->id === $b->id) {
            throw ValidationException::withMessages(['op' => 'Cannot merge an operation with itself.']);
        }
        if ($a->planned_order_id !== $b->planned_order_id) {
            throw ValidationException::withMessages(['op' => 'Only operations on the same planned order can be merged.']);
        }
        if ($a->resource_type !== $b->resource_type || $a->resource_ref_id !== $b->resource_ref_id) {
            throw ValidationException::withMessages(['op' => 'Only operations on the same resource can be merged.']);
        }
        if (in_array(ScheduleOp::STATUS_RELEASED, [$a->status, $b->status], true)) {
            throw ValidationException::withMessages(['op' => 'A released operation can no longer be merged.']);
        }

        [$first, $second] = $a->planned_start->lte($b->planned_start) ? [$a, $b] : [$b, $a];
        if ($second->planned_start->gt($first->planned_end)) {
            throw ValidationException::withMessages(['op' => 'Only contiguous or overlapping operations can be merged.']);
        }

        return DB::transaction(function () use ($first, $second) {
            $first->update([
                'planned_end' => $first->planned_end->greaterThan($second->planned_end) ? $first->planned_end : $second->planned_end,
                'status' => in_array(ScheduleOp::STATUS_COMMITTED, [$first->status, $second->status], true)
                    ? ScheduleOp::STATUS_COMMITTED
                    : ScheduleOp::STATUS_DRAFT,
            ]);
            $second->delete();

            return $first->refresh();
        });
    }

    /**
     * PP_SPECS.md §3I — apply a dispatch strategy to one resource's draft queue. Only `seq` is
     * rewritten (never `planned_start`/`planned_end`): a window move would silently re-trigger
     * §3H's conflict invariant against committed rows on the same resource, and scoping to drafts
     * only means "apply a sort rule" can never quietly move a committed/released commitment.
     *
     * @return Collection<int, ScheduleOp> the reordered rows, seq already persisted
     */
    public function applyStrategy(string $resourceType, int $resourceRefId, string $strategy): Collection
    {
        $ops = ScheduleOp::query()->baseline()
            ->with('plannedOrder:id,created_at,need_by_date,source_type,source_id,recipe_id,bom_id,product_id')
            ->where('resource_type', $resourceType)
            ->where('resource_ref_id', $resourceRefId)
            ->where('status', ScheduleOp::STATUS_DRAFT)
            ->orderBy('seq')
            ->get();

        $ordered = $this->rules->apply($strategy, $ops);

        DB::transaction(function () use ($ordered) {
            $seq = 1;
            foreach ($ordered as $op) {
                $op->update(['seq' => $seq++]);
            }
        });

        return $ordered;
    }

    private function guardProductionOrder(int $plannedOrderId): void
    {
        $order = PlannedOrder::query()->find($plannedOrderId);

        if (! $order) {
            throw ValidationException::withMessages(['planned_order_id' => 'Planned order not found.']);
        }
        if ($order->order_type !== PlannedOrder::TYPE_PRODUCTION) {
            throw ValidationException::withMessages(['planned_order_id' => 'Only a production planned order has operations to schedule.']);
        }
        if ($order->status === PlannedOrder::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['planned_order_id' => 'Cannot schedule an operation against a cancelled planned order.']);
        }
    }

    private function guardNoConflict(ScheduleOp $op): void
    {
        if (! in_array($op->status, [ScheduleOp::STATUS_COMMITTED, ScheduleOp::STATUS_RELEASED], true)) {
            return; // drafts are exploratory and never conflict
        }
        if (! $op->resource_type || ! $op->resource_ref_id) {
            return;
        }

        $conflict = ScheduleOp::query()->baseline()
            ->when($op->exists, fn ($q) => $q->where('id', '!=', $op->id))
            ->where('resource_type', $op->resource_type)
            ->where('resource_ref_id', $op->resource_ref_id)
            ->whereIn('status', [ScheduleOp::STATUS_COMMITTED, ScheduleOp::STATUS_RELEASED])
            ->where('planned_start', '<', $op->planned_end)
            ->where('planned_end', '>', $op->planned_start)
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'planned_start' => "Conflicts with operation #{$conflict->id} on the same resource ({$conflict->planned_start->toDateTimeString()} – {$conflict->planned_end->toDateTimeString()}).",
            ]);
        }
    }
}
