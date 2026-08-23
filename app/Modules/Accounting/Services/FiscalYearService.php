<?php

namespace App\Modules\Accounting\Services;

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

    /** §3O period locking: soft-close blocks ordinary posting, hard-close blocks all posting. */
    public function setPeriodStatus(FiscalPeriod $period, string $status): FiscalPeriod
    {
        if (! in_array($status, FiscalPeriod::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid period status.']);
        }

        $period->update(['status' => $status]);

        return $period->refresh();
    }
}
