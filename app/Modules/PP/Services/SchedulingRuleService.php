<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ScheduleOp;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * PP_SPECS.md §3I — a pluggable dispatch-strategy service: `apply(strategy, operations)` reorders
 * and returns the same collection, it never touches the database (§3H's own conflict/commitment
 * invariants live in ScheduleOpService, which is also the only thing that persists the result —
 * as a `seq` rewrite, scoped to draft ops, never a window move; see that service's
 * `applyStrategy()`).
 *
 * "Per resource group" (spec prose) isn't wired for the single-criterion strategies:
 * `pp_schedule_ops` targets one specific MES resource (`resource_type`/`resource_ref_id`), not a
 * `pp_resource_groups` row — resource groups have no first-class link to a schedule-op queue yet.
 * The buildable, honest unit today is one resource's own draft queue, which is what a real
 * shop-floor dispatch list looks like anyway. `minimize_setup`/`minimize_changeover` are the
 * exception: they resolve the resource's group via `ResourceGroupMember` themselves (see
 * `ChangeoverMatrixService::lookup()`), so the matrix *can* be defined per resource group even
 * though the queue being reordered is still one resource's.
 *
 * `minimize_setup`/`minimize_changeover` (§3J) use a greedy nearest-neighbor construction, not a
 * stable per-item sort key — each step picks whichever remaining op is cheapest to switch to from
 * the last-placed one — because minimizing total changeover cost is a sequencing problem, not a
 * ranking one. This is a heuristic, not an optimal TSP solve; good enough for a shop-floor
 * dispatch list, and cheap to keep O(n²) for queues this size (single-resource draft queues).
 */
class SchedulingRuleService
{
    public const STRATEGY_FIFO = 'fifo';

    public const STRATEGY_EARLIEST_DUE_DATE = 'earliest_due_date';

    public const STRATEGY_SHORTEST_PROCESSING_TIME = 'shortest_processing_time';

    public const STRATEGY_LONGEST_PROCESSING_TIME = 'longest_processing_time';

    public const STRATEGY_PRIORITY = 'priority';

    public const STRATEGY_CAMPAIGN = 'campaign';

    public const STRATEGY_MINIMIZE_SETUP = 'minimize_setup';

    public const STRATEGY_MINIMIZE_CHANGEOVER = 'minimize_changeover';

    /** Selectable today — real data behind every one of these. */
    public const AVAILABLE = [
        self::STRATEGY_FIFO,
        self::STRATEGY_EARLIEST_DUE_DATE,
        self::STRATEGY_SHORTEST_PROCESSING_TIME,
        self::STRATEGY_LONGEST_PROCESSING_TIME,
        self::STRATEGY_PRIORITY,
        self::STRATEGY_CAMPAIGN,
        self::STRATEGY_MINIMIZE_SETUP,
        self::STRATEGY_MINIMIZE_CHANGEOVER,
    ];

    /** Nothing pending today — §3J shipped, unblocking minimize_setup/minimize_changeover. Kept as an extension point. */
    public const PENDING = [];

    /** @var array<string, string> */
    public const LABELS = [
        self::STRATEGY_FIFO => 'FIFO (arrival order)',
        self::STRATEGY_EARLIEST_DUE_DATE => 'Earliest Due Date',
        self::STRATEGY_SHORTEST_PROCESSING_TIME => 'Shortest Processing Time (maximizes utilization)',
        self::STRATEGY_LONGEST_PROCESSING_TIME => 'Longest Processing Time',
        self::STRATEGY_PRIORITY => 'Priority (sales-order-linked first)',
        self::STRATEGY_CAMPAIGN => 'Campaign Production (group by recipe)',
        self::STRATEGY_MINIMIZE_SETUP => 'Minimize Setup (§3J matrix, setup time only)',
        self::STRATEGY_MINIMIZE_CHANGEOVER => 'Minimize Changeover (§3J matrix, setup + cleaning time)',
    ];

    public function __construct(protected ChangeoverMatrixService $changeover) {}

    /**
     * @param  Collection<int, ScheduleOp>  $operations
     * @return Collection<int, ScheduleOp> the same operations, reordered — caller decides whether/how to persist
     */
    public function apply(string $strategy, Collection $operations): Collection
    {
        if (in_array($strategy, self::PENDING, true)) {
            throw ValidationException::withMessages([
                'strategy' => "\"{$strategy}\" requires PP_SPECS.md §3J's Setup & Changeover Matrix, which is not built yet.",
            ]);
        }

        if (! in_array($strategy, self::AVAILABLE, true)) {
            throw ValidationException::withMessages(['strategy' => "Unknown scheduling strategy: {$strategy}."]);
        }

        if (in_array($strategy, [self::STRATEGY_MINIMIZE_SETUP, self::STRATEGY_MINIMIZE_CHANGEOVER], true)) {
            return $this->applyChangeoverGreedy($strategy, $operations);
        }

        return $operations
            ->sort(fn (ScheduleOp $a, ScheduleOp $b) => $this->sortKey($strategy, $a) <=> $this->sortKey($strategy, $b))
            ->values();
    }

    /**
     * PP_SPECS.md §3J — greedy nearest-neighbor construction (see class docblock for why a static
     * sort key can't express this). Seeded by earliest due date so an urgent job never starves
     * behind an arbitrary first pick; every following step appends whichever remaining op is
     * cheapest to switch to from the last-placed one, tie-broken by due date.
     *
     * @param  Collection<int, ScheduleOp>  $operations
     * @return Collection<int, ScheduleOp>
     */
    private function applyChangeoverGreedy(string $strategy, Collection $operations): Collection
    {
        if ($operations->isEmpty()) {
            return $operations;
        }

        $remaining = $operations->values()->all();
        usort($remaining, fn (ScheduleOp $a, ScheduleOp $b) => $this->dueDateKey($a) <=> $this->dueDateKey($b));

        $ordered = [];
        $current = array_shift($remaining);
        $ordered[] = $current;

        while ($remaining) {
            usort($remaining, function (ScheduleOp $a, ScheduleOp $b) use ($current, $strategy) {
                $cost = $this->changeoverCost($strategy, $current, $a) <=> $this->changeoverCost($strategy, $current, $b);

                return $cost !== 0 ? $cost : $this->dueDateKey($a) <=> $this->dueDateKey($b);
            });
            $current = array_shift($remaining);
            $ordered[] = $current;
        }

        return collect($ordered)->values();
    }

    private function dueDateKey(ScheduleOp $op): int
    {
        return $op->plannedOrder?->need_by_date?->timestamp ?? PHP_INT_MAX;
    }

    /** minimize_setup counts changeover time only; minimize_changeover counts changeover + cleaning (§3J's "total ... setup + cleaning time"). */
    private function changeoverCost(string $strategy, ScheduleOp $from, ScheduleOp $to): int
    {
        // No resource assigned yet (a draft op created before its resource was picked) — nothing
        // to look up a group for, so treat it as a free changeover rather than erroring.
        if (! $to->resource_type || ! $to->resource_ref_id) {
            return 0;
        }

        $cost = $this->changeover->lookup(
            $to->resource_type,
            (int) $to->resource_ref_id,
            $from->plannedOrder?->product_id,
            $to->plannedOrder?->product_id,
        );

        return $strategy === self::STRATEGY_MINIMIZE_SETUP
            ? $cost['changeover_minutes']
            : $cost['changeover_minutes'] + $cost['cleaning_minutes'];
    }

    /**
     * A tuple, always — single-criterion strategies just return a one-element array. PHP's `<=>`
     * compares arrays lexicographically element by element, so `apply()` can use one comparator
     * for every strategy instead of branching per strategy in the sort itself.
     *
     * @return array<int, mixed>
     */
    private function sortKey(string $strategy, ScheduleOp $op): array
    {
        return match ($strategy) {
            // "Arrival order" — when the driving planned order was created, not when this op's
            // window happens to fall (that's Earliest Due Date's job).
            self::STRATEGY_FIFO => [$op->plannedOrder?->created_at?->timestamp ?? PHP_INT_MAX],
            self::STRATEGY_EARLIEST_DUE_DATE => [$op->plannedOrder?->need_by_date?->timestamp ?? PHP_INT_MAX],
            // Duration = the planner's own proposed window (planned_end - planned_start), same
            // "no MES routing standard time exists yet" posture CapacityPlanService documents for
            // required/available hours — not a routing-derived processing time.
            self::STRATEGY_SHORTEST_PROCESSING_TIME => [$op->planned_start->diffInMinutes($op->planned_end)],
            self::STRATEGY_LONGEST_PROCESSING_TIME => [-$op->planned_start->diffInMinutes($op->planned_end)],
            // SALES.so_hdrs carries no numeric priority column — sales-order-linked demand
            // dispatches before everything else, tie-broken by due date, rather than inventing a
            // priority scale the source data doesn't have.
            self::STRATEGY_PRIORITY => [
                $this->isSalesOrderLinked($op) ? 0 : 1,
                $op->plannedOrder?->need_by_date?->timestamp ?? PHP_INT_MAX,
            ],
            // §3K's own framing, generalized to any BOM/recipe-driven order today: group same
            // recipe/BOM runs together so the floor doesn't alternate between them.
            self::STRATEGY_CAMPAIGN => [
                $op->plannedOrder?->recipe_id ?? $op->plannedOrder?->bom_id ?? PHP_INT_MAX,
                $op->planned_start->timestamp,
            ],
            default => [0],
        };
    }

    /**
     * Traces planned_order → its driving demand line (only set for independently-netted, not
     * BOM-exploded, orders — MrpService's own documented limitation) → that line's demand header
     * → whether it was sourced from a confirmed Sales order (DemandAggregationService's real,
     * already-wired sync path).
     */
    private function isSalesOrderLinked(ScheduleOp $op): bool
    {
        $order = $op->plannedOrder;
        if (! $order || $order->source_type !== 'demand_line' || ! $order->source_id) {
            return false;
        }

        $line = DemandLine::query()->with('header:id,source_type')->find($order->source_id);

        return $line?->header?->source_type === DemandHeader::SOURCE_SALES_ORDER;
    }
}
