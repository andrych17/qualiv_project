<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * §3M tax period register (masa pajak) — due-date rules are tenant-editable data
 * (SYSCONFIG.config_consts, customization ladder rung 1, CLAUDE.md §2), never hardcoded,
 * since due-date regulations change. Seeded defaults (AccountingSeeder): PPN due end of
 * the following month, PPh withholding remittance due the 10th of the following month
 * (§3M) — both overridable per tenant without a code deploy.
 */
class TaxPeriodService
{
    public function __construct(protected ConfigService $config) {}

    /** Get-or-create — a tax document being issued for a not-yet-registered masa pajak registers it on demand. */
    public function ensurePeriod(int $companyId, string $obligationType, string $masaPajak): TaxPeriod
    {
        $existing = TaxPeriod::query()
            ->where('company_id', $companyId)
            ->where('obligation_type', $obligationType)
            ->where('masa_pajak', $masaPajak)
            ->first();

        if ($existing) {
            return $existing;
        }

        return TaxPeriod::query()->create([
            'company_id' => $companyId,
            'obligation_type' => $obligationType,
            'masa_pajak' => $masaPajak,
            'due_date' => $this->computeDueDate($obligationType, $masaPajak),
            'filing_status' => TaxPeriod::STATUS_OPEN,
        ]);
    }

    public function markFiled(TaxPeriod $period): TaxPeriod
    {
        if ($period->filing_status === TaxPeriod::STATUS_FILED) {
            throw ValidationException::withMessages(['filing_status' => 'This period is already marked filed.']);
        }

        $period->update(['filing_status' => TaxPeriod::STATUS_FILED, 'filed_at' => now()]);

        return $period->refresh();
    }

    private function computeDueDate(string $obligationType, string $masaPajak): Carbon
    {
        $followingMonth = Carbon::createFromFormat('Y-m-d', "{$masaPajak}-01")->addMonthNoOverflow();

        $dayOfMonth = (int) ($this->config->get('ACCOUNTING_TAX', $obligationType === TaxPeriod::OBLIGATION_PPN ? 'PPN_DUE_DAY_OF_MONTH' : 'PPH_DUE_DAY_OF_MONTH')
            ?? ($obligationType === TaxPeriod::OBLIGATION_PPN ? 0 : 10));

        return $dayOfMonth > 0 ? $followingMonth->copy()->day($dayOfMonth) : $followingMonth->copy()->endOfMonth();
    }
}
