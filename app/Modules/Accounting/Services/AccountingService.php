<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApPayment;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Models\GlJournal;

/**
 * §3R Automation from ERP — the same-process integration point for any Core/Vertical module
 * that needs to touch financials without knowing double-entry rules. Every method here is a
 * thin pass-through to an already-existing service (JournalService/ArInvoiceService/
 * ApBillService/ArPaymentService/ApPaymentService/AccountLedgerService) — this class adds no
 * business logic of its own, only a single stable entry point so a caller doesn't need to
 * know which of six services to reach for.
 *
 * The event bus (InvoiceRequested/ApBillRequested/PaymentRequested/ApPaymentRequested/
 * JournalPostingRequested — §3R's other integration path, "decoupled, preferred for
 * cross-module triggers") hits the exact same underlying services through their own
 * listeners — this facade and the event bus are two doors into the same room, never two
 * different behaviors. Use this facade for a same-process call where the caller wants the
 * result immediately; use the event bus when the caller wants to fire-and-forget across a
 * module boundary.
 *
 * createInvoice()/createBill()/recordPayment()/recordApPayment() create DRAFTS only, same
 * "never auto-post with no human in the loop" discipline as their event-bus listener
 * counterparts (CreateInvoiceFromRequest et al. — see each listener's own docblock).
 * postJournal() is the one exception, since it's the direct analogue of §3H/§3S's
 * auto-posting engines: the caller has already decided the journal should exist and be
 * posted, there's nothing left for a human to review.
 */
class AccountingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ArInvoiceService $arInvoices,
        private readonly ApBillService $apBills,
        private readonly ArPaymentService $arPayments,
        private readonly ApPaymentService $apPayments,
        private readonly AccountLedgerService $ledger,
    ) {}

    /**
     * Creates AND posts a journal in one call — see class docblock for why this is the one
     * facade method that doesn't stop at draft.
     *
     * @param  array{company_id:int, fiscal_period_id:int, journal_date:string, currency_code:string, memo?:?string, subject_type?:?string, subject_id?:?string}  $header
     * @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, fx_currency_code?:?string, fx_amount?:?float, fx_rate?:?float, description?:?string}>  $lines
     */
    public function postJournal(array $header, array $lines, ?int $userId, string $source = GlJournal::SOURCE_MANUAL): GlJournal
    {
        $journal = $this->journals->create($header, $lines, $userId, $source);

        return $this->journals->post($journal, $userId);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createInvoice(array $header, array $lines, ?int $userId): ArInvoice
    {
        return $this->arInvoices->create($header, $lines, $userId);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createBill(array $header, array $lines, ?int $userId): ApBill
    {
        return $this->apBills->create($header, $lines, $userId);
    }

    /** @param  array<string, mixed>  $header */
    public function recordPayment(array $header, ?array $applications, ?int $userId): ArPayment
    {
        return $this->arPayments->create($header, $applications, $userId);
    }

    /**
     * AP-side mirror of recordPayment() — the spec's own bullet only names "recordPayment",
     * but ApPaymentRequested/ApPaymentService are already this facade's event-bus and
     * service-layer equivalents on the AP side; recordPayment() alone would leave the facade
     * unable to reach half of what the event bus already covers.
     *
     * @param  array<string, mixed>  $header
     */
    public function recordApPayment(array $header, ?array $applications, ?int $userId): ApPayment
    {
        return $this->apPayments->create($header, $applications, $userId);
    }

    /**
     * The current signed balance of one account, by code — the exact integration point
     * §3J's own spec text names for Performance's budget-category mapping
     * (`PERF.budget_category_accounts`, see ACCOUNTING_SPECS.md §3J: "Accounting exposes
     * nothing new for this — AccountingService::getAccountBalance(...) already covers it").
     * Throws if the account doesn't exist for this company — a missing account is a tenant
     * configuration problem worth surfacing, not silently reading as zero.
     */
    public function getAccountBalance(int $companyId, string $accountCode, ?string $throughDate = null): float
    {
        $account = Account::query()->where('company_id', $companyId)->where('account_code', $accountCode)->firstOrFail();

        return $this->ledger->forAccount($account, $throughDate)['closingBalance'];
    }
}
