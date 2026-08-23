<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\ApPaymentRecorded;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApPayment;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3E — vendor disbursement. Mirrors ArPaymentService (Dr/Cr reversed: this posts Dr AP
 * control / Cr Cash, since we're reducing what we owe rather than what's owed to us).
 * create()/post() split the same way — see ApPaymentService's AR counterpart docblock for
 * why an automated caller only ever gets a draft.
 */
class ApPaymentService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ApBillService $bills,
    ) {}

    /**
     * @param  array{company_id:int, partner_id:int, cash_gl_account_id:int, currency_code:string, payment_date:string, amount:float, memo?:?string}  $header
     * @param  list<array{ap_bill_id:int, applied_amount:float}>|null  $applications  null = auto-apply oldest-due-first across the partner's open bills
     */
    public function create(array $header, ?array $applications, ?int $userId): ApPayment
    {
        return DB::transaction(function () use ($header, $applications, $userId) {
            $amount = (float) $header['amount'];
            $resolvedApplications = $applications ?? $this->autoApply($header['company_id'], $header['partner_id'], $amount);

            $this->assertApplicationsValid($header['company_id'], $header['partner_id'], $resolvedApplications, $amount);

            $payment = ApPayment::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'status' => ApPayment::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            foreach ($resolvedApplications as $app) {
                $payment->applications()->create([
                    'ap_bill_id' => $app['ap_bill_id'],
                    'applied_amount' => $app['applied_amount'],
                ]);
            }

            return $payment->refresh();
        });
    }

    public function post(ApPayment $payment, int $userId): ApPayment
    {
        if ($payment->status !== ApPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft payment can be posted.']);
        }

        return DB::transaction(function () use ($payment, $userId) {
            $payment->load('applications.bill', 'company');
            $company = $payment->company ?? Company::query()->findOrFail($payment->company_id);

            if (! $company->ap_control_account_id) {
                throw ValidationException::withMessages(['company_id' => "{$company->legal_name} has no AP control account configured — set one on the company before posting payments."]);
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

            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $payment->payment_date->toDateString(),
                'currency_code' => $payment->currency_code,
                'memo' => "AP Payment #{$payment->id}",
                'subject_type' => 'accounting.ap_payments',
                'subject_id' => (string) $payment->id,
            ], [
                ['account_id' => $company->ap_control_account_id, 'debit' => (float) $payment->amount, 'description' => "AP — payment #{$payment->id}"],
                ['account_id' => $payment->cash_gl_account_id, 'credit' => (float) $payment->amount, 'description' => "Payment disbursed #{$payment->id}"],
            ], $userId, 'ap');

            $this->journals->post($journal, $userId);

            $payment->update(['status' => ApPayment::STATUS_POSTED, 'journal_id' => $journal->id]);

            $billIds = [];
            foreach ($payment->applications as $app) {
                $bill = $app->bill;
                $bill->increment('paid_amount', (float) $app->applied_amount);
                $this->bills->recalculateStatus($bill->refresh());
                $billIds[] = $bill->id;
            }

            ApPaymentRecorded::dispatch($payment->id, $billIds);

            return $payment->refresh();
        });
    }

    public function delete(ApPayment $payment): void
    {
        if ($payment->status !== ApPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft (unposted) payment can be deleted — reverse the posted journal instead once posted.']);
        }
        $payment->delete();
    }

    /** Oldest-due-first, up to $amount — fails loudly rather than leaving an unapplied cash balance (§3E has no "on account" concept, same as §3D). */
    private function autoApply(int $companyId, int $partnerId, float $amount): array
    {
        $openBills = ApBill::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereIn('status', [ApBill::STATUS_POSTED, ApBill::STATUS_PARTIALLY_PAID])
            ->orderBy('due_date')
            ->get();

        $remaining = $amount;
        $applications = [];

        foreach ($openBills as $bill) {
            if ($remaining <= 0.005) {
                break;
            }
            $open = $bill->openBalance();
            if ($open <= 0.005) {
                continue;
            }
            $apply = min($open, $remaining);
            $applications[] = ['ap_bill_id' => $bill->id, 'applied_amount' => round($apply, 2)];
            $remaining -= $apply;
        }

        if ($remaining > 0.005) {
            throw ValidationException::withMessages(['amount' => 'This payment exceeds the vendor\'s total open bill balance — specify applications manually or reduce the amount.']);
        }

        return $applications;
    }

    /** @param  list<array{ap_bill_id:int, applied_amount:float}>  $applications */
    private function assertApplicationsValid(int $companyId, int $partnerId, array $applications, float $amount): void
    {
        if (empty($applications)) {
            throw ValidationException::withMessages(['applications' => 'A payment needs at least one bill application.']);
        }

        $sum = round(array_sum(array_column($applications, 'applied_amount')), 2);
        if (abs($sum - round($amount, 2)) > 0.005) {
            throw ValidationException::withMessages(['applications' => "Applications ({$sum}) must total the payment amount ({$amount})."]);
        }

        foreach ($applications as $app) {
            $bill = ApBill::query()->find($app['ap_bill_id']);
            if ($bill === null || $bill->company_id !== $companyId || $bill->partner_id !== $partnerId) {
                throw ValidationException::withMessages(['applications' => 'One of the selected bills is invalid for this partner/company.']);
            }
            if ((float) $app['applied_amount'] > $bill->openBalance() + 0.005) {
                throw ValidationException::withMessages(['applications' => "Applied amount for bill {$bill->bill_no} exceeds its open balance."]);
            }
        }
    }
}
