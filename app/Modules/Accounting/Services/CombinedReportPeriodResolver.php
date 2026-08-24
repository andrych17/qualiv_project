<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;

/**
 * §3N — shared by every "combined across companies" report mode (Trial Balance, Balance
 * Sheet, P&L, Cash Flow). v1 simplification: matches each company's own fiscal period to the
 * reference period by period_no, not by date-range overlap — correct for tenants where every
 * company shares the same fiscal_year_start_month (the common case), but two companies on
 * genuinely offset calendars won't line up. Flagged here rather than silently assumed; a
 * date-overlap resolver is a contained follow-up if a tenant ever needs it.
 */
class CombinedReportPeriodResolver
{
    public function resolve(Company $company, FiscalPeriod $referencePeriod): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('period_no', $referencePeriod->period_no)
            ->first();
    }
}
