<?php

namespace App\Modules\MES\Services;

use App\Modules\HCM\Models\ShiftAssignment;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\ShiftHandoverNote;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3P — shift handover notes. `order_summary` is captured once, at creation time
 * ("order/batch summary at handover time" — the spec's own words), never recomputed later; this
 * is what makes a handover note a reliable point-in-time record rather than a live report that
 * silently changes underneath a reader.
 */
class ShiftHandoverService
{
    /** @param  array{shift_assignment_id: int, notes?: string|null}  $data */
    public function create(array $data, int $userId): ShiftHandoverNote
    {
        $assignment = ShiftAssignment::query()->find($data['shift_assignment_id']);
        if ($assignment === null) {
            throw ValidationException::withMessages(['shift_assignment_id' => 'The selected shift assignment is invalid.']);
        }

        return ShiftHandoverNote::query()->create([
            'shift_assignment_id' => $assignment->id,
            'order_summary' => $this->snapshot(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $activeOrders = ProdOrder::query()
            ->whereIn('status', [ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED])
            ->get(['id', 'order_number', 'status']);

        return [
            'active_orders' => $activeOrders->map(fn (ProdOrder $o) => ['order_number' => $o->order_number, 'status' => $o->status])->all(),
            'open_qc_hold_count' => QcHold::query()->open()->count(),
            'open_downtime_count' => DowntimeEvent::query()->open()->count(),
        ];
    }
}
