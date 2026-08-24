<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;

/**
 * §3Q AR/AP control reconciliation — per the spec, NOT a matching problem (see §3D/§3E: every
 * AR/AP posting already goes through the one control account via JournalService, so there's
 * nothing to pair up). This is a read-only trust/audit check: does the control account's GL
 * balance agree with the sum of open subledger items?
 *
 * Open items are summed in BASE currency (openBalance() × the document's own stored fx_rate),
 * not transaction currency — the control account itself is always base currency, so comparing
 * it against a mixed-currency sum of transaction-currency balances would show a fake variance
 * on any tenant with even one foreign-currency invoice or bill.
 */
class ControlReconciliationService
{
    /** @return array{controlBalance: float, openItemsTotal: float, variance: float, openItemCount: int} */
    public function arReport(Company $company): array
    {
        $company->loadMissing('arControlAccount');

        $controlBalance = $this->controlAccountBalance($company->arControlAccount);

        $invoices = ArInvoice::query()->where('company_id', $company->id)->get(['total_amount', 'paid_amount', 'credited_amount', 'fx_rate']);
        $open = $invoices->filter(fn (ArInvoice $i) => $i->openBalance() !== 0.0);
        $openItemsTotal = round($open->sum(fn (ArInvoice $i) => $i->openBalance() * (float) ($i->fx_rate ?? 1.0)), 2);

        return [
            'controlBalance' => $controlBalance,
            'openItemsTotal' => $openItemsTotal,
            'variance' => round($controlBalance - $openItemsTotal, 2),
            'openItemCount' => $open->count(),
        ];
    }

    /** @return array{controlBalance: float, openItemsTotal: float, variance: float, openItemCount: int} */
    public function apReport(Company $company): array
    {
        $company->loadMissing('apControlAccount');

        $controlBalance = $this->controlAccountBalance($company->apControlAccount);

        $bills = ApBill::query()->where('company_id', $company->id)->get(['total_amount', 'withheld_amount', 'paid_amount', 'debited_amount', 'fx_rate']);
        $open = $bills->filter(fn (ApBill $b) => $b->openBalance() !== 0.0);
        $openItemsTotal = round($open->sum(fn (ApBill $b) => $b->openBalance() * (float) ($b->fx_rate ?? 1.0)), 2);

        return [
            'controlBalance' => $controlBalance,
            'openItemsTotal' => $openItemsTotal,
            'variance' => round($controlBalance - $openItemsTotal, 2),
            'openItemCount' => $open->count(),
        ];
    }

    /**
     * §3H — narrower than AR/AP's report: Inventory's own valuation total
     * (`stock_valuation_layers`) doesn't exist yet (Inventory only has item/category CRUD
     * today, not the Goods Receipt/Issue/Adjustment engine or costing layers). This reports
     * the GL half only — `valuationTotal`/`variance` are null, not a fabricated 0 or a fake
     * match, until Inventory's valuation reporting (`INVENTORY_SPECS.md` §3I) ships.
     *
     * @return array{controlBalance: float, valuationTotal: ?float, variance: ?float}
     */
    public function inventoryReport(Company $company): array
    {
        $company->loadMissing('inventoryControlAccount');

        return [
            'controlBalance' => $this->controlAccountBalance($company->inventoryControlAccount),
            'valuationTotal' => null,
            'variance' => null,
        ];
    }

    /**
     * §3S — same "narrower than AR/AP" shape as inventoryReport(): Payroll has zero real
     * code yet (only scaffolding), so there's no "open unpaid net pay" total to compare
     * against. Reports the GL half only.
     *
     * @return array{controlBalance: float, openTotal: ?float, variance: ?float}
     */
    public function payrollReport(Company $company): array
    {
        $company->loadMissing('payrollNetPayPayableAccount');

        return [
            'controlBalance' => $this->controlAccountBalance($company->payrollNetPayPayableAccount),
            'openTotal' => null,
            'variance' => null,
        ];
    }

    private function controlAccountBalance(?Account $account): float
    {
        if (! $account) {
            return 0.0;
        }

        $isDebitNormal = $account->normal_balance === Account::BALANCE_DEBIT;

        $totals = GlJournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED))
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $delta = $isDebitNormal
            ? (float) $totals->total_debit - (float) $totals->total_credit
            : (float) $totals->total_credit - (float) $totals->total_debit;

        return round($delta, 2);
    }
}
