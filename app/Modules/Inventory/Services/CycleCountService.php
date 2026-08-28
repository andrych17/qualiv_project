<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Adjustment;
use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\CycleCount;
use App\Modules\Inventory\Models\CycleCountLine;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §3Q Cycle Counting — a scheduled count scoped by location, category, or ABC class, worked
 * via scan-to-count entry. "Completing a count with variances routes to Adjustment (§3G) for
 * review/approval before posting — counting itself never silently changes stock": complete()
 * drafts one Adjustment per counted location (Adjustment's own scoping unit) and stops there —
 * it never calls AdjustmentService::post().
 */
class CycleCountService
{
    public function __construct(protected AdjustmentService $adjustments) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): CycleCount
    {
        $balances = $this->matchingBalances($data);

        if ($balances->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'No stock balances match this scope — nothing to count.']);
        }

        return DB::transaction(function () use ($data, $balances) {
            $count = CycleCount::query()->create([
                'warehouse_id' => $data['warehouse_id'],
                'location_id' => $data['location_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'abc_class' => $data['abc_class'] ?? null,
                'status' => CycleCount::STATUS_PENDING,
                'assigned_to' => $data['assigned_to'] ?? null,
                'scheduled_date' => $data['scheduled_date'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($balances as $balance) {
                CycleCountLine::query()->create([
                    'cycle_count_id' => $count->id,
                    'product_id' => $balance->product_id,
                    'location_id' => $balance->location_id,
                    'batch_id' => $balance->batch_id,
                    'system_qty' => $balance->qty_on_hand,
                    'status' => CycleCountLine::STATUS_PENDING,
                ]);
            }

            return $count->load('lines');
        });
    }

    public function assign(CycleCount $count, ?int $userId): void
    {
        $count->update(['assigned_to' => $userId]);
    }

    /** §3Q "scan-to-count entry" — the two barcode scans are verified client-side; this is the "confirm quantity" step. */
    public function countLine(CycleCountLine $line, float $countedQty): void
    {
        if ($line->status !== CycleCountLine::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This line has already been counted.']);
        }
        if ($countedQty < 0) {
            throw ValidationException::withMessages(['counted_qty' => 'Counted quantity cannot be negative.']);
        }

        DB::transaction(function () use ($line, $countedQty) {
            $line->update([
                'status' => CycleCountLine::STATUS_COUNTED,
                'counted_qty' => $countedQty,
                'counted_at' => now(),
                'counted_by' => auth()->id(),
            ]);

            $count = $line->cycleCount()->lockForUpdate()->first();
            if ($count->status === CycleCount::STATUS_PENDING) {
                $count->update(['status' => CycleCount::STATUS_IN_PROGRESS]);
            }
        });
    }

    /**
     * @return array{count: CycleCount, adjustments: Collection<int, Adjustment>}
     */
    public function complete(CycleCount $count): array
    {
        if ($count->status === CycleCount::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['status' => 'This count is already completed.']);
        }
        if ($count->lines()->where('status', CycleCountLine::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages(['lines' => 'Every line must be counted before completing.']);
        }

        $reasonId = AdjustmentReason::query()->where('code', 'count_variance')->value('id');
        if ($reasonId === null) {
            throw ValidationException::withMessages(['status' => 'No "Count variance" adjustment reason is configured.']);
        }

        $lines = $count->lines()->with('product')->get()->groupBy('location_id');

        $adjustments = DB::transaction(function () use ($count, $lines, $reasonId) {
            $created = collect();

            foreach ($lines as $locationId => $group) {
                $created->push($this->adjustments->create([
                    'warehouse_id' => $count->warehouse_id,
                    'location_id' => $locationId,
                    'adjustment_date' => now()->toDateString(),
                    'reason_id' => $reasonId,
                    'reference' => "Cycle Count #{$count->id}",
                    'lines' => $group->map(fn (CycleCountLine $l) => [
                        'product_id' => $l->product_id,
                        'batch_id' => $l->batch_id,
                        'system_qty' => $l->system_qty,
                        'counted_qty' => $l->counted_qty,
                    ])->all(),
                ]));
            }

            $count->update(['status' => CycleCount::STATUS_COMPLETED, 'completed_at' => now()]);

            return $created;
        });

        return ['count' => $count->refresh(), 'adjustments' => $adjustments];
    }

    /** Only a count with no counted lines yet can be scrapped — matches PickList::delete()'s posture. */
    public function delete(CycleCount $count): void
    {
        if ($count->lines()->where('status', CycleCountLine::STATUS_COUNTED)->exists()) {
            throw ValidationException::withMessages(['status' => 'This count already has counted lines and can\'t be deleted.']);
        }

        $count->delete();
    }

    /** @param  array<string, mixed>  $data  @return Collection<int, StockBalance> */
    private function matchingBalances(array $data): Collection
    {
        return StockBalance::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->when($data['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($data['category_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($q) => $q->where('category_id', $v)))
            ->when($data['abc_class'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($q) => $q->where('abc_class', $v)))
            ->get();
    }
}
