<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\PaymentRecorded;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3D — customer payment application. create()/post() are split the same way
 * JournalService's manual entry is: create() alone is safe for an unattended
 * caller (the future PaymentRequested listener draft-creates without posting to
 * the GL — no human has reviewed an automated request), while a human-driven
 * screen calls both in the same request since submitting the form is the review.
 *
 * §3L: a payment converts at its OWN rate (resolved fresh on payment_date), not
 * the rate the invoice originally booked at — settling a foreign-currency
 * invoice at a different rate than it was booked is realized FX gain/loss,
 * deliberately deferred (no batch job or auto-posted gain/loss entry exists
 * yet). The consequence: such a payment can leave a small residual on the AR
 * control account for that invoice until realized-gain/loss lands. Applications
 * are restricted to invoices in the payment's own currency (assertApplicationsValid)
 * so this residual is at least confined to genuine rate movement, not a currency
 * mismatch.
 */
class ArPaymentService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ArInvoiceService $invoices,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param  array{company_id:int, partner_id:int, cash_gl_account_id:int, currency_code:string, payment_date:string, amount:float, memo?:?string}  $header
     * @param  list<array{ar_invoice_id:int, applied_amount:float}>|null  $applications  null = auto-apply oldest-first across the partner's open invoices
     */
    public function create(array $header, ?array $applications, ?int $userId): ArPayment
    {
        return DB::transaction(function () use ($header, $applications, $userId) {
            $amount = (float) $header['amount'];
            $resolvedApplications = $applications ?? $this->autoApply($header['company_id'], $header['partner_id'], $amount);

            $this->assertApplicationsValid($header['company_id'], $header['partner_id'], $header['currency_code'], $resolvedApplications, $amount);

            $payment = ArPayment::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'status' => ArPayment::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            foreach ($resolvedApplications as $app) {
                $payment->applications()->create([
                    'ar_invoice_id' => $app['ar_invoice_id'],
                    'applied_amount' => $app['applied_amount'],
                ]);
            }

            return $payment->refresh();
        });
    }

    public function post(ArPayment $payment, int $userId): ArPayment
    {
        if ($payment->status !== ArPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft payment can be posted.']);
        }

        return DB::transaction(function () use ($payment, $userId) {
            $payment->load('applications.invoice', 'company');
            $company = $payment->company ?? Company::query()->findOrFail($payment->company_id);

            if (! $company->ar_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AR control account configured — set one on the company before posting payments."]);
            }

            $period = FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('status', FiscalPeriod::STATUS_OPEN)
                ->where('start_date', '<=', $payment->payment_date)
                ->where('end_date', '>=', $payment->payment_date)
                ->first();
            if ($period === null) {
                throw ValidationException::withMessages(['payment_date' => 'No open fiscal period covers this payment\'s date.']);
            }

            $rate = $this->exchangeRates->rateFor($company, $payment->currency_code, $payment->payment_date->toDateString());
            $isForeign = $payment->currency_code !== $company->base_currency;
            $base = round((float) $payment->amount * $rate, 2);
            $fxTrio = $isForeign
                ? ['fx_currency_code' => $payment->currency_code, 'fx_amount' => (float) $payment->amount, 'fx_rate' => $rate]
                : [];

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $payment->payment_date->toDateString(),
                'currency_code' => $payment->currency_code,
                'memo' => "AR Payment #{$payment->id}",
                'subject_type' => 'accounting.ar_payments',
                'subject_id' => (string) $payment->id,
            ], [
                ['account_id' => $payment->cash_gl_account_id, 'debit' => $base, 'description' => "Payment received #{$payment->id}", ...$fxTrio],
                ['account_id' => $company->ar_control_account_id, 'credit' => $base, 'description' => "AR — payment #{$payment->id}", ...$fxTrio],
            ], $userId, 'ar');

            $this->journals->post($journal, $userId);

            $payment->update(['status' => ArPayment::STATUS_POSTED, 'journal_id' => $journal->id]);

            $invoiceIds = [];
            foreach ($payment->applications as $app) {
                $invoice = $app->invoice;
                $invoice->increment('paid_amount', (float) $app->applied_amount);
                $this->invoices->recalculateStatus($invoice->refresh());
                $invoiceIds[] = $invoice->id;
            }

            AuditLog::record([
                'company_id' => $company->id,
                'action' => AuditLog::ACTION_PAYMENT_POSTED,
                'subject_type' => 'accounting.ar_payments',
                'subject_id' => $payment->id,
                'actor_id' => $userId,
            ]);

            PaymentRecorded::dispatch($payment->id, $invoiceIds);

            return $payment->refresh();
        });
    }

    public function delete(ArPayment $payment): void
    {
        if ($payment->status !== ArPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft (unposted) payment can be deleted — reverse the posted journal instead once posted.']);
        }
        $payment->delete();
    }

    /** Oldest-due-first, up to $amount — fails loudly rather than leaving an unapplied cash balance (§3D has no "on account" concept). */
    private function autoApply(int $companyId, int $partnerId, float $amount): array
    {
        $openInvoices = ArInvoice::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereIn('status', [ArInvoice::STATUS_POSTED, ArInvoice::STATUS_PARTIALLY_PAID])
            ->orderBy('due_date')
            ->get();

        $remaining = $amount;
        $applications = [];

        foreach ($openInvoices as $invoice) {
            if ($remaining <= 0.005) {
                break;
            }
            $open = $invoice->openBalance();
            if ($open <= 0.005) {
                continue;
            }
            $apply = min($open, $remaining);
            $applications[] = ['ar_invoice_id' => $invoice->id, 'applied_amount' => round($apply, 2)];
            $remaining -= $apply;
        }

        if ($remaining > 0.005) {
            throw ValidationException::withMessages(['amount' => 'This payment exceeds the partner\'s total open invoice balance — specify applications manually or reduce the amount.']);
        }

        return $applications;
    }

    /** @param  list<array{ar_invoice_id:int, applied_amount:float}>  $applications */
    private function assertApplicationsValid(int $companyId, int $partnerId, string $currencyCode, array $applications, float $amount): void
    {
        if (empty($applications)) {
            throw ValidationException::withMessages(['applications' => 'A payment needs at least one invoice application.']);
        }

        $sum = round(array_sum(array_column($applications, 'applied_amount')), 2);
        if (abs($sum - round($amount, 2)) > 0.005) {
            throw ValidationException::withMessages(['applications' => "Applications ({$sum}) must total the payment amount ({$amount})."]);
        }

        foreach ($applications as $app) {
            $invoice = ArInvoice::query()->find($app['ar_invoice_id']);
            if ($invoice === null || $invoice->company_id !== $companyId || $invoice->partner_id !== $partnerId) {
                throw ValidationException::withMessages(['applications' => 'One of the selected invoices is invalid for this partner/company.']);
            }
            // §3L: a payment only ever converts at its own single rate (see post()) — an
            // invoice in a different currency than the payment can't settle against it.
            if ($invoice->currency_code !== $currencyCode) {
                throw ValidationException::withMessages(['applications' => "Invoice {$invoice->invoice_no} is in {$invoice->currency_code}, not this payment's currency ({$currencyCode})."]);
            }
            if ((float) $app['applied_amount'] > $invoice->openBalance() + 0.005) {
                throw ValidationException::withMessages(['applications' => "Applied amount for invoice {$invoice->invoice_no} exceeds its open balance."]);
            }
        }
    }
}
