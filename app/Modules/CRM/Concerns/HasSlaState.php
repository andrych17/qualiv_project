<?php

namespace App\Modules\CRM\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared breached/due_soon/on_track split for ServiceCase (§3E) and Ticket (§3F) — both
 * reuse the exact same status vocabulary and SLA mechanics per CRM_SPECS.md §3F ("List/detail
 * views mirror After Sales Service"). Kept as one trait, not two copies, so the SQL filter
 * and the PHP display accessor can't drift apart between the two models.
 */
trait HasSlaState
{
    public const DUE_SOON_HOURS = 24;

    /** A row in either of these is no longer "at risk" — SLA state reads as on_track regardless of due date. */
    public const CLOSED_STATUSES = ['resolved', 'closed'];

    public function scopeFilterSlaState(Builder $query, ?string $state): void
    {
        if (! $state) {
            return;
        }

        $now = Carbon::now();
        $soon = $now->copy()->addHours(self::DUE_SOON_HOURS);

        match ($state) {
            'breached' => $query->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', $now)
                ->whereNotIn('status', self::CLOSED_STATUSES),
            'due_soon' => $query->whereNotNull('sla_due_at')
                ->whereBetween('sla_due_at', [$now, $soon])
                ->whereNotIn('status', self::CLOSED_STATUSES),
            'on_track' => $query->where(function (Builder $query) use ($soon) {
                $query->whereIn('status', self::CLOSED_STATUSES)
                    ->orWhereNull('sla_due_at')
                    ->orWhere('sla_due_at', '>', $soon);
            }),
            default => null,
        };
    }

    /** PHP mirror of scopeFilterSlaState's SQL — for display on an already-fetched row, not for querying. */
    public function getSlaStateAttribute(): string
    {
        if (in_array($this->status, self::CLOSED_STATUSES, true) || $this->sla_due_at === null) {
            return 'on_track';
        }

        if ($this->sla_due_at->isPast()) {
            return 'breached';
        }

        if ($this->sla_due_at->lessThanOrEqualTo(now()->addHours(self::DUE_SOON_HOURS))) {
            return 'due_soon';
        }

        return 'on_track';
    }
}
