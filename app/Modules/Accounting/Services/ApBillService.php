<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\ApBillPosted;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3E — vendor bills. Mirrors ArInvoiceService; post() always resolves each line's input
 * tax code and the bill's withholding type, issuing an input Faktur Pajak (§3M) and/or a
 * Bukti Potong in the same transaction — same "written against the tax engine from the
 * start" discipline as §3D.
 *
 * §3L: same base-currency-conversion discipline as ArInvoiceService — see its docblock.
 */
class ApBillService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly FakturPajakService $fakturPajak,
        private readonly BuktiPotongService $buktiPotong,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array{company_id:int, partner_id:int, bill_no:string, currency_code:string, issue_date:string, due_date:string, vendor_faktur_no?:?string, withholding_type_id?:?int, subject_type?:?string, subject_id?:?int}  $header
     * @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, expense_account_id:int}>  $lines
     */
    public function create(array $header, array $lines, ?int $userId): ApBill
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $bill = ApBill::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'status' => ApBill::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            $this->replaceLines($bill, $lines);

            return $bill->refresh();
        });
    }

    /** @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, expense_account_id:int}>  $lines */
    public function update(ApBill $bill, array $header, array $lines): ApBill
    {
        $this->assertDraft($bill);

        return DB::transaction(function () use ($bill, $header, $lines) {
            $bill->update($header);
            $this->replaceLines($bill, $lines);

            return $bill->refresh();
        });
    }

    public function delete(ApBill $bill): void
    {
        $this->assertDraft($bill);
        $bill->delete();
    }

    /**
     * §3E: posts Dr Expense/Asset (per line) + Dr PPN Masukan (input-taxable lines) /
     * Cr AP control (payable = gross - withheld) + Cr PPh Payable (withheld, if any).
     * Issues an input Faktur Pajak for taxable lines (requires vendor_faktur_no — fails
     * loud if missing, symmetric with §3D's output-side treatment) and a Bukti Potong for
     * withholding (requires the withholding type's bp_type to be configured). All in one
     * transaction — either the whole bill posts correctly-taxed, or none of it does.
     */
    public function post(ApBill $bill, int $userId): ApBill
    {
        $this->assertDraft($bill);

        return DB::transaction(function () use ($bill, $userId) {
            $bill->load(['lines.taxCode', 'company', 'withholdingType']);

            if ($bill->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'A bill needs at least one line before it can be posted.']);
            }

            $company = $bill->company;
            if (! $company->ap_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AP control account configured — set one on the company before posting bills."]);
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $bill->issue_date)
                ->where('end_date', '>=', $bill->issue_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['issue_date' => 'No open fiscal period covers this bill\'s issue date.']);
            }

            $subtotal = (float) $bill->lines->sum('line_amount');
            $taxAmount = (float) $bill->lines->sum('tax_amount');
            $total = round($subtotal + $taxAmount, 2);

            $withholdingType = $bill->withholdingType;
            if ($withholdingType !== null && ! $withholdingType->bp_type) {
                throw ValidationException::withMessages(['withholding_type_id' => "Withholding type {$withholdingType->code} has no Bukti Potong type configured — set one before posting."]);
            }
            $withheld = $withholdingType !== null ? round($subtotal * ((float) $withholdingType->rate / 100), 2) : 0.0;
            $payable = round($total - $withheld, 2);

            // §3L: same rate-resolution and "derive the total from its converted
            // components" discipline as ArInvoiceService — see that docblock.
            $rate = $this->exchangeRates->rateFor($company, $bill->currency_code, $bill->issue_date->toDateString());
            $isForeign = $bill->currency_code !== $company->base_currency;
            $toBase = fn (float $amount): float => round($amount * $rate, 2);
            $fxTrio = fn (float $txnAmount): array => $isForeign
                ? ['fx_currency_code' => $bill->currency_code, 'fx_amount' => $txnAmount, 'fx_rate' => $rate]
                : [];

            $journalLines = [];
            $debitsBase = 0.0;

            foreach ($bill->lines->groupBy('expense_account_id') as $expenseAccountId => $group) {
                $txnAmount = (float) $group->sum('line_amount');
                $base = $toBase($txnAmount);
                $debitsBase += $base;
                $journalLines[] = ['account_id' => (int) $expenseAccountId, 'debit' => $base, 'description' => "Expense — {$bill->bill_no}", ...$fxTrio($txnAmount)];
            }

            $taxableBase = 0.0;
            foreach ($bill->lines->whereNotNull('tax_code_id')->groupBy('tax_code_id') as $taxCodeId => $group) {
                $taxCode = $group->first()->taxCode;
                $lineTax = (float) $group->sum('tax_amount');
                if ($lineTax <= 0) {
                    continue;
                }
                $base = $toBase($lineTax);
                $debitsBase += $base;
                $journalLines[] = ['account_id' => $taxCode->gl_account_id, 'debit' => $base, 'description' => "PPN Masukan {$taxCode->code} — {$bill->bill_no}", ...$fxTrio($lineTax)];
                $taxableBase += (float) $group->sum('line_amount');
            }

            // credit(payable) + credit(withheld) is made to equal debitsBase exactly by
            // deriving payableBase as the remainder, not by converting $payable on its own.
            $withheldBase = $withheld > 0 ? $toBase($withheld) : 0.0;
            $payableBase = round($debitsBase - $withheldBase, 2);

            $journalLines[] = ['account_id' => $company->ap_control_account_id, 'credit' => $payableBase, 'description' => "AP — {$bill->bill_no}", ...$fxTrio($payable)];
            if ($withheldBase > 0) {
                $journalLines[] = ['account_id' => $withholdingType->gl_payable_account_id, 'credit' => $withheldBase, 'description' => "PPh {$withholdingType->code} withheld — {$bill->bill_no}", ...$fxTrio($withheld)];
            }

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $bill->issue_date->toDateString(),
                'currency_code' => $bill->currency_code,
                'memo' => "AP Bill {$bill->bill_no}",
                'subject_type' => 'accounting.ap_bills',
                'subject_id' => (string) $bill->id,
            ], $journalLines, $userId, 'ap');

            $this->journals->post($journal, $userId);

            $bill->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'withheld_amount' => $withheld,
                'total_amount' => $total,
                'fx_rate' => $rate,
                'status' => ApBill::STATUS_POSTED,
                'journal_id' => $journal->id,
            ]);

            // §3L: $taxableBase/$taxAmount/$withheld below are TRANSACTION currency
            // (never converted) — a DJP tax document is denominated in whatever
            // currency the underlying bill actually is, same as $bill->total_amount.
            if ($taxAmount > 0) {
                if (! $bill->vendor_faktur_no) {
                    throw ValidationException::withMessages(['vendor_faktur_no' => 'This bill has a taxable line — the vendor\'s Faktur Pajak number is required before posting.']);
                }
                $this->fakturPajak->recordInput(
                    companyId: $company->id,
                    apBillId: $bill->id,
                    partnerId: $bill->partner_id,
                    nomorSeriFaktur: $bill->vendor_faktur_no,
                    taxBase: $taxableBase,
                    ppnAmount: $taxAmount,
                    issueDate: $bill->issue_date->toDateString(),
                );
            }

            if ($withheld > 0) {
                $this->buktiPotong->issue(
                    companyId: $company->id,
                    bpType: $withholdingType->bp_type,
                    apBillId: $bill->id,
                    withholdingTypeId: $withholdingType->id,
                    partnerId: $bill->partner_id,
                    grossAmount: $subtotal,
                    withheldAmount: $withheld,
                    issueDate: $bill->issue_date->toDateString(),
                );
            }

            AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_BILL_POSTED,
                'subject_type' => 'accounting.ap_bills',
                'subject_id' => $bill->id,
                'actor_id' => $userId,
            ]);

            ApBillPosted::dispatch($bill->id, $bill->subject_type, $bill->subject_id);

            return $bill->refresh();
        });
    }

    /** §3E: the single formula behind status everywhere (payments, debit notes, aging). */
    public function recalculateStatus(ApBill $bill): void
    {
        $open = $bill->openBalance();

        $status = match (true) {
            $open <= 0.005 => ApBill::STATUS_PAID,
            (float) $bill->paid_amount > 0 || (float) $bill->debited_amount > 0 => ApBill::STATUS_PARTIALLY_PAID,
            default => ApBill::STATUS_POSTED,
        };

        $bill->update(['status' => $status]);
    }

    private function assertDraft(ApBill $bill): void
    {
        if ($bill->status !== ApBill::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft bill can be edited, posted, or deleted.']);
        }
    }

    /** @param  list<array{description:string, qty:float, unit_price:float, discount_amount?:float, tax_code_id?:?int, expense_account_id:int}>  $lines */
    private function replaceLines(ApBill $bill, array $lines): void
    {
        $bill->lines()->delete();

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

            $bill->lines()->create([
                'line_no' => $i + 1,
                'description' => $line['description'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_code_id' => $taxCodeId,
                'expense_account_id' => $line['expense_account_id'],
                'line_amount' => $lineAmount,
                'tax_amount' => $taxAmount,
            ]);
        }
    }
}
