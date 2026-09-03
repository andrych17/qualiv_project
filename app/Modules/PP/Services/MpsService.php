<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\MpsHeader;
use App\Modules\PP\Models\MpsLine;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PP_SPECS.md §3C — the MPS grid: presentation plus firm/release actions over §3D's existing
 * planned orders (build-order note, PP_SPECS.md §6), not a redesign of MrpService into
 * period-bucketed netting. §3D nets one planned order per product per run (not per period), so
 * a period cell's firm/release controls are only active once a planned order's `need_by_date`
 * falls inside that period — a genuine MVP limitation, not an oversight.
 *
 * `is_frozen` is an edit lock on `planned_qty` only (see MpsLine docblock) — it does not block
 * MRP from replanning the period, since that needs period-bucketed netting §3D deliberately
 * doesn't have. Firming a period's matching planned order (via PlannedOrderService::firm(),
 * PP_SPECS.md §3D/§3C) is the real "exclude from automatic MRP regeneration" mechanism.
 */
class MpsService
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ConfigService $config,
        protected PlannedOrderService $plannedOrders,
    ) {}

    /** @return list<array{start: string, end: string, label: string}> */
    public function periods(): array
    {
        $type = (string) ($this->config->get('PP', 'MPS_PERIOD_TYPE') ?? 'week');
        $horizon = (int) ($this->config->get('PP', 'MPS_HORIZON_PERIODS') ?? 8);

        $periods = [];
        $cursor = $type === 'month' ? Carbon::now()->startOfMonth() : Carbon::now()->startOfWeek(Carbon::MONDAY);

        for ($i = 0; $i < $horizon; $i++) {
            $start = $cursor->copy();
            $end = $type === 'month' ? $start->copy()->endOfMonth() : $start->copy()->addDays(6);

            $periods[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $type === 'month'
                    ? $start->format('M Y')
                    : 'W'.$start->isoWeek.' ('.$start->format('M j').'–'.$end->format('j').')',
            ];

            $cursor = $type === 'month' ? $cursor->copy()->addMonthNoOverflow() : $cursor->copy()->addWeek();
        }

        return $periods;
    }

    public function getOrCreateHeader(int $productId): MpsHeader
    {
        return DB::transaction(function () use ($productId) {
            $header = MpsHeader::query()->baseline()->firstOrCreate([
                'product_id' => $productId,
                'scenario_id' => null,
            ]);
            $this->ensurePeriods($header);

            return $header;
        });
    }

    public function ensurePeriods(MpsHeader $header): void
    {
        foreach ($this->periods() as $period) {
            MpsLine::query()->firstOrCreate(
                ['mps_hdr_id' => $header->id, 'period_start' => $period['start']],
                ['period_end' => $period['end'], 'planned_qty' => 0],
            );
        }
    }

    public function removeHeader(MpsHeader $header): void
    {
        $header->delete();
    }

    public function updateQty(MpsLine $line, float $qty): MpsLine
    {
        if ($line->is_frozen) {
            throw ValidationException::withMessages(['planned_qty' => 'This period is frozen — unfreeze it before editing the quantity.']);
        }

        $line->update(['planned_qty' => $qty]);

        return $line->refresh();
    }

    public function setFrozen(MpsLine $line, bool $frozen): MpsLine
    {
        $line->update(['is_frozen' => $frozen]);

        return $line->refresh();
    }

    public function firm(MpsLine $line): MpsLine
    {
        $order = $this->matchingPlannedOrder($line);
        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'No planned order falls in this period yet — run MRP first.']);
        }

        $this->plannedOrders->firm($order);

        return $line;
    }

    public function unfirm(MpsLine $line): MpsLine
    {
        $order = $this->matchingPlannedOrder($line);
        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'No firmed planned order found in this period.']);
        }

        $this->plannedOrders->unfirm($order);

        return $line;
    }

    public function release(MpsLine $line): MpsLine
    {
        $order = $this->matchingPlannedOrder($line);
        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'No planned order falls in this period yet — run MRP first.']);
        }

        $released = $this->plannedOrders->release($order);
        $line->update(['released_planned_order_id' => $released->id]);

        return $line->refresh();
    }

    /**
     * PP_SPECS.md §3C — computed on read, not a separate stored drill-down table: Demand
     * (§3B), Planned Production/Orders (§3D), Material (AvailabilityService). Capacity (§3F)
     * and inline exception codes (§3M) aren't built yet, so `capacity` is always null here —
     * the grid should render that as "not available yet", not a fabricated number.
     *
     * @return array<string, mixed>
     */
    public function drillDown(MpsLine $line): array
    {
        $header = $line->header;

        $demandQty = (float) DemandLine::query()->baseline()
            ->where('product_id', $header->product_id)
            ->whereBetween('need_by_date', [$line->period_start, $line->period_end])
            ->sum('qty');

        $order = $line->released_planned_order_id
            ? PlannedOrder::query()->find($line->released_planned_order_id)
            : $this->matchingPlannedOrder($line);

        return [
            'demand_qty' => $demandQty,
            'is_shortfall' => (float) $line->planned_qty < $demandQty,
            'available_qty' => $this->availability->totalAvailableQty($header->product_id),
            'capacity' => null,
            'planned_order' => $order ? [
                'id' => $order->id,
                'plan_number' => $order->plan_number,
                'order_type' => $order->order_type,
                'qty' => (float) $order->qty,
                'status' => $order->status,
            ] : null,
        ];
    }

    private function matchingPlannedOrder(MpsLine $line): ?PlannedOrder
    {
        $header = $line->header;

        return PlannedOrder::query()->baseline()
            ->where('product_id', $header->product_id)
            ->whereBetween('need_by_date', [$line->period_start, $line->period_end])
            ->whereIn('status', [PlannedOrder::STATUS_PLANNED, PlannedOrder::STATUS_FIRMED])
            ->first();
    }
}
