<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\InvoicePosted;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\CRM\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3D — customer invoices. AR/AP is written against the §3M tax engine from the
 * start: post() always resolves each line's tax code and, for any taxable line,
 * issues a Faktur Pajak via FakturPajakService (§3M) in the same transaction —
 * an invoice/bill screen that doesn't call tax logic is unfinished, not a
 * shippable interim state (§5 build-order note).
 *
 * §3L: the invoice keeps its lines/subtotal/total in transaction currency (what
 * the customer sees); post() converts to base currency per journal line via
 * ExchangeRateService::rateFor() and stores the rate used on invoice.fx_rate.
 * A base-currency invoice never touches the rate table (rate is always 1.0),
 * so this is a no-op for every tenant until a real foreign-currency invoice
 * exists.
 */
class ArInvoiceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly FakturPajakService $fakturPajak,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array{company_id:int, partner_id:int, currency_code:string, issue_date:string, due_date:string, invoice_type?:string, subject_type?:?string, subject_id?:?int}  $header
     * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines
     */
    public function create(array $header, array $lines, ?int $userId): ArInvoice
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            // Locked the same way BuktiPotongService locks its company row (§3M) —
            // no natural "book" row exists for invoice numbering either.
            $company = Company::query()->lockForUpdate()->findOrFail($header['company_id']);

            $invoice = ArInvoice::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'invoice_no' => $this->nextNumber($company, 'INV'),
                'invoice_type' => $header['invoice_type'] ?? ArInvoice::TYPE_STANDARD,
                'status' => ArInvoice::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            $this->replaceLines($invoice, $lines);

            return $invoice->refresh();
        });
    }

    /** @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines */
    public function update(ArInvoice $invoice, array $header, array $lines): ArInvoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $header, $lines) {
            $invoice->update($header);
            $this->replaceLines($invoice, $lines);

            return $invoice->refresh();
        });
    }

    public function delete(ArInvoice $invoice): void
    {
        $this->assertDraft($invoice);
        $invoice->delete();
    }

    /**
     * §3D: posts the AR control-account journal (Dr AR control / Cr Revenue +
     * Cr PPN Output per taxable line), issues a Faktur Pajak for the taxable
     * portion if any, and fires InvoicePosted. All in one transaction — a Faktur
     * Pajak block exhaustion (§3M) rolls back the whole posting, not just the
     * tax document, so a taxable invoice never posts without correct PPN
     * treatment (§5 build-order note).
     */
    public function post(ArInvoice $invoice, int $userId): ArInvoice
    {
        $this->assertDraft($invoice);

        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->load(['lines.taxCode', 'company']);

            if ($invoice->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'An invoice needs at least one line before it can be posted.']);
            }

            $company = $invoice->company;
            if (! $company->ar_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AR control account configured — set one on the company before posting invoices."]);
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $invoice->issue_date)
                ->where('end_date', '>=', $invoice->issue_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['issue_date' => 'No open fiscal period covers this invoice\'s issue date.']);
            }

            $subtotal = (float) $invoice->lines->sum('line_amount');
            $taxAmount = (float) $invoice->lines->sum('tax_amount');
            $total = round($subtotal + $taxAmount, 2);

            // §3L: rate resolved once per posting, at 1.0 with no table lookup for a
            // base-currency invoice (the common case) — see ExchangeRateService::rateFor().
            $rate = $this->exchangeRates->rateFor($company, $invoice->currency_code, $invoice->issue_date->toDateString());
            $isForeign = $invoice->currency_code !== $company->base_currency;
            $toBase = fn (float $amount): float => round($amount * $rate, 2);
            $fxTrio = fn (float $txnAmount): array => $isForeign
                ? ['fx_currency_code' => $invoice->currency_code, 'fx_amount' => $txnAmount, 'fx_rate' => $rate]
                : [];

            // AR control debit is the SUM of the already-converted credit components
            // below, not an independent conversion of $total — guarantees the journal
            // balances exactly even where per-line rounding would otherwise drift a cent.
            $journalLines = [];
            $totalBase = 0.0;

            foreach ($invoice->lines->groupBy('revenue_account_id') as $revenueAccountId => $group) {
                $txnAmount = (float) $group->sum('line_amount');
                $base = $toBase($txnAmount);
                $totalBase += $base;
                $journalLines[] = ['account_id' => (int) $revenueAccountId, 'credit' => $base, 'description' => "Revenue — {$invoice->invoice_no}", ...$fxTrio($txnAmount)];
            }

            $taxableBase = 0.0;
            foreach ($invoice->lines->whereNotNull('tax_code_id')->groupBy('tax_code_id') as $taxCodeId => $group) {
                $taxCode = $group->first()->taxCode;
                $lineTax = (float) $group->sum('tax_amount');
                if ($lineTax <= 0) {
                    continue;
                }
                $base = $toBase($lineTax);
                $totalBase += $base;
                $journalLines[] = ['account_id' => $taxCode->gl_account_id, 'credit' => $base, 'description' => "PPN Output {$taxCode->code} — {$invoice->invoice_no}", ...$fxTrio($lineTax)];
                $taxableBase += (float) $group->sum('line_amount');
            }

            array_unshift($journalLines, ['account_id' => $company->ar_control_account_id, 'debit' => $totalBase, 'description' => "AR — {$invoice->invoice_no}", ...$fxTrio($total)]);

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $invoice->issue_date->toDateString(),
                'currency_code' => $invoice->currency_code,
                'memo' => "AR Invoice {$invoice->invoice_no}",
                'subject_type' => 'accounting.ar_invoices',
                'subject_id' => (string) $invoice->id,
            ], $journalLines, $userId, 'ar');

            $this->journals->post($journal, $userId);

            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $total,
                'fx_rate' => $rate,
                'status' => ArInvoice::STATUS_POSTED,
                'journal_id' => $journal->id,
            ]);

            // §3L: $taxableBase/$taxAmount below are TRANSACTION currency (never
            // converted) — same as ApBillService's input-side Faktur/Bukti Potong call.
            if ($taxAmount > 0) {
                $partner = Partner::query()->find($invoice->partner_id);
                $this->fakturPajak->issueOutput(
                    companyId: $company->id,
                    arInvoiceId: $invoice->id,
                    partnerId: $invoice->partner_id,
                    buyerNpwpNik: $partner?->registration_tax_id,
                    taxBase: $taxableBase,
                    ppnAmount: $taxAmount,
                    issueDate: $invoice->issue_date->toDateString(),
                );
            }

            InvoicePosted::dispatch($invoice->id, $invoice->subject_type, $invoice->subject_id);

            return $invoice->refresh();
        });
    }

    /** §3D: the single formula behind status everywhere (payments, credit notes, aging). */
    public function recalculateStatus(ArInvoice $invoice): void
    {
        $open = $invoice->openBalance();

        $status = match (true) {
            $open <= 0.005 => ArInvoice::STATUS_PAID,
            (float) $invoice->paid_amount > 0 || (float) $invoice->credited_amount > 0 => ArInvoice::STATUS_PARTIALLY_PAID,
            default => ArInvoice::STATUS_POSTED,
        };

        $invoice->update(['status' => $status]);
    }

    private function assertDraft(ArInvoice $invoice): void
    {
        if ($invoice->status !== ArInvoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft invoice can be edited, posted, voided, or deleted.']);
        }
    }

    /** @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, revenue_account_id:int}>  $lines */
    private function replaceLines(ArInvoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();

        if (empty($lines)) {
            return;
        }

        $taxRates = TaxCode::query()->whereIn('id', array_filter(array_column($lines, 'tax_code_id')))->pluck('rate', 'id');

        foreach (array_values($lines) as $i => $line) {
            $qty = (float) $line['qty'];
            $unitPrice = (float) $line['unit_price'];
            $discount = (float) ($line['discount_amount'] ?? 0);
            $lineAmount = round(($qty * $unitPrice) - $discount, 2);
            $taxCodeId = $line['tax_code_id'] ?? null;
            $taxAmount = $taxCodeId && $taxRates->has($taxCodeId)
                ? round($lineAmount * ((float) $taxRates[$taxCodeId] / 100), 2)
                : 0.0;

            $invoice->lines()->create([
                'line_no' => $i + 1,
                'description' => $line['description'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_code_id' => $taxCodeId,
                'revenue_account_id' => $line['revenue_account_id'],
                'line_amount' => $lineAmount,
                'tax_amount' => $taxAmount,
            ]);
        }
    }

    private function nextNumber(Company $company, string $prefix): string
    {
        $year = now()->year;
        // Not a DJP-regulated document (unlike Faktur Pajak) — gaps from a
        // deleted draft are acceptable, so a simple locked count is enough.
        $seq = ArInvoice::query()->where('company_id', $company->id)->whereYear('created_at', $year)->count() + 1;

        return sprintf('%s/%d/%05d', $prefix, $year, $seq);
    }
}
