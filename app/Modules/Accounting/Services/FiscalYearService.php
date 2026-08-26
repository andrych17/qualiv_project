<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** §3B fiscal calendar — a fiscal year always ships with its 12 monthly periods, never created empty. */
class FiscalYearService
{
    public function create(int $companyId, int $year, string $startDate): FiscalYear
    {
        return DB::transaction(function () use ($companyId, $year, $startDate) {
            $start = Carbon::parse($startDate);
            $end = $start->copy()->addYear()->subDay();

            $fiscalYear = FiscalYear::query()->create([
                'company_id' => $companyId,
                'year' => $year,
                'start_date' => $start,
                'end_date' => $end,
                'status' => FiscalYear::STATUS_OPEN,
            ]);

            $periodStart = $start->copy();
            for ($periodNo = 1; $periodNo <= 12; $periodNo++) {
                $periodEnd = $periodStart->copy()->addMonth()->subDay();

                FiscalPeriod::query()->create([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYear->id,
                    'period_no' => $periodNo,
                    'start_date' => $periodStart->copy(),
                    'end_date' => $periodEnd,
                    'status' => FiscalPeriod::STATUS_OPEN,
                ]);

                $periodStart = $periodStart->addMonth();
            }

            return $fiscalYear->refresh();
        });
    }

    /** §3O: how restrictive each status is — used to classify a transition as a close or a reopen, not just "is it open now." */
    private const STATUS_RANK = [
        FiscalPeriod::STATUS_OPEN => 0,
        FiscalPeriod::STATUS_SOFT_CLOSED => 1,
        FiscalPeriod::STATUS_HARD_CLOSED => 2,
    ];

    /**
     * §3O period locking: soft-close blocks ordinary posting, hard-close blocks all posting
     * (see JournalService::assertPeriodOpen()). Every transition is audited — moving to a
     * more restrictive status logs period_closed, less restrictive logs period_reopened
     * (so hard_closed -> soft_closed is correctly a reopen-class event, not just "not open yet").
     */
    public function setPeriodStatus(FiscalPeriod $period, string $status): FiscalPeriod
    {
        if (! in_array($status, FiscalPeriod::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid period status.']);
        }

        if ($status === FiscalPeriod::STATUS_OPEN && $period->status !== FiscalPeriod::STATUS_OPEN) {
            $fiscalYear = $period->fiscalYear;
            if ($fiscalYear) {
                $hasSubsequentClosedYear = FiscalYear::query()
                    ->where('company_id', $period->company_id)
                    ->where('year', '>', $fiscalYear->year)
                    ->where('status', FiscalYear::STATUS_CLOSED)
                    ->exists();

                if ($hasSubsequentClosedYear) {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot reopen a period when a subsequent fiscal year is already closed.',
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($period, $status) {
            $fromStatus = $period->status;
            $period->update(['status' => $status]);

            if (self::STATUS_RANK[$status] > self::STATUS_RANK[$fromStatus]) {
                $action = AuditLog::ACTION_PERIOD_CLOSED;
            } elseif (self::STATUS_RANK[$status] < self::STATUS_RANK[$fromStatus]) {
                $action = AuditLog::ACTION_PERIOD_REOPENED;
            } else {
                $action = null;
            }

            if ($action !== null) {
                AuditLog::record([
                    'company_id' => $period->company_id,
                    'action' => $action,
                    'subject_type' => 'accounting.fiscal_periods',
                    'subject_id' => $period->id,
                    'before_snapshot' => ['status' => $fromStatus],
                    'after_snapshot' => ['status' => $status],
                ]);
            }

            return $period->refresh();
        });
    }
}
