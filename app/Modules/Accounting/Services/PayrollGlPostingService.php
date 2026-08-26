<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use App\Modules\Accounting\Models\PayrollGlPosting;
use App\Modules\Accounting\Models\PayrollPostingFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3S — posts the financial side of a paid Payroll run, using only the totals Payroll has
 * already computed (never a locally recalculated figure — this engine holds zero payroll
 * calculation logic, per spec).
 *
 * Balancing arithmetic: every 'earning' line debits its mapped account; every
 * 'employer_cost' line debits its mapped account AND credits its mapped payable_account_id;
 * every 'deduction' line credits its mapped account. Net pay payable (earnings minus
 * deductions — employer_cost never touches it) is the final credit line, to the company's
 * one designated payroll_net_pay_payable_account_id. Total debits (earnings +
 * employer_cost) always equals total credits (deductions + employer_cost's payable side +
 * net pay), since net pay = earnings - deductions by construction.
 *
 * Idempotent on (subject_type, subject_id) — a replayed event (queue retry, or a manual
 * Retry from the failure queue) is a safe no-op if already posted. A run with any
 * unmapped/incomplete component, no Net Pay Payable control account, or no fiscal period
 * covering its date "fails loudly and queues for review rather than posting to a suspense
 * account silently" (spec rule) — the WHOLE run queues for review (never a partial post of
 * only the mapped components), it never throws.
 */
class PayrollGlPostingService
{
    public function __construct(private readonly JournalService $journals) {}

    public function postRunPaid(PayrollRunPaid $event): void
    {
        if ($this->alreadyPosted($event->subjectType, $event->subjectId)) {
            return;
        }

        $company = Company::query()->find($event->companyId);
        if (! $company) {
            return;
        }

        $lines = $this->aggregateLines($event->lines);

        $mappings = PayrollComponentGlMapping::query()
            ->where('company_id', $company->id)
            ->whereIn('component_code', array_keys($lines))
            ->get()
            ->keyBy('component_code');

        $missing = array_diff(array_keys($lines), $mappings->keys()->all());
        if ($missing !== []) {
            $this->recordFailure($event, 'No GL mapping found for component(s): '.implode(', ', $missing).'.');

            return;
        }

        $incomplete = $mappings->filter(fn (PayrollComponentGlMapping $m) => $m->component_type === PayrollComponentGlMapping::TYPE_EMPLOYER_COST && ! $m->payable_account_id);
        if ($incomplete->isNotEmpty()) {
            $this->recordFailure($event, 'Mapping(s) missing a payable account for employer-cost component(s): '.$incomplete->pluck('component_code')->implode(', ').'.');

            return;
        }

        if (! $company->payroll_net_pay_payable_account_id) {
            $this->recordFailure($event, 'No Net Pay Payable control account configured for this company.');

            return;
        }

        $period = $this->resolveFiscalPeriod($company->id, $event->runDate);
        if (! $period) {
            $this->recordFailure($event, 'No fiscal period covers this run date.');

            return;
        }

        $glLines = [];
        $netPay = 0.0;

        foreach ($lines as $code => $amount) {
            $amount = round($amount, 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $mapping = $mappings->get($code);

            if ($mapping->component_type === PayrollComponentGlMapping::TYPE_EARNING) {
                $glLines[] = ['account_id' => $mapping->gl_account_id, 'debit' => $amount, 'description' => $mapping->component_label];
                $netPay += $amount;
            } elseif ($mapping->component_type === PayrollComponentGlMapping::TYPE_DEDUCTION) {
                $glLines[] = ['account_id' => $mapping->gl_account_id, 'credit' => $amount, 'description' => $mapping->component_label];
                $netPay -= $amount;
            } else { // employer_cost
                $glLines[] = ['account_id' => $mapping->gl_account_id, 'debit' => $amount, 'description' => $mapping->component_label];
                $glLines[] = ['account_id' => $mapping->payable_account_id, 'credit' => $amount, 'description' => $mapping->component_label.' (payable)'];
            }
        }

        $netPay = round($netPay, 2);
        if ($netPay > 0.005) {
            $glLines[] = ['account_id' => $company->payroll_net_pay_payable_account_id, 'credit' => $netPay, 'description' => 'Net pay payable'];
        } elseif ($netPay < -0.005) {
            $glLines[] = ['account_id' => $company->payroll_net_pay_payable_account_id, 'debit' => -$netPay, 'description' => 'Net pay payable'];
        }

        if ($glLines === []) {
            return; // every line rounded to zero — nothing to post, safe to skip (naturally idempotent, no row needed)
        }

        DB::transaction(function () use ($company, $period, $event, $glLines) {
            $journal = $this->journals->create([
                'company_id' => $company->id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $event->runDate,
                'currency_code' => $company->base_currency,
                'memo' => $event->memo ?? 'Payroll run',
                'subject_type' => $event->subjectType,
                'subject_id' => $event->subjectId,
            ], $glLines, null, 'payroll');

            $journal = $this->journals->post($journal, null);

            $this->finalizePosting($event, $journal);
        });
    }

    /** Rebuilds the event from a failure row's stored payload and re-attempts posting — a no-op if it was already resolved by a concurrent retry. */
    public function retry(PayrollPostingFailure $failure): void
    {
        if ($failure->status === PayrollPostingFailure::STATUS_RESOLVED) {
            throw ValidationException::withMessages(['failure' => 'This run was already resolved.']);
        }

        $this->postRunPaid(new PayrollRunPaid(...$failure->payload));
    }

    private function alreadyPosted(string $subjectType, string $subjectId): bool
    {
        return PayrollGlPosting::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->exists();
    }

    private function resolveFiscalPeriod(int $companyId, string $runDate): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $runDate)
            ->whereDate('end_date', '>=', $runDate)
            ->first();
    }

    /**
     * @param  list<array{component_code:string, amount:float}>  $lines
     * @return array<string, float> component_code => summed amount, defensive against a duplicate code appearing twice in one event's $lines
     */
    private function aggregateLines(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $totals[$line['component_code']] = ($totals[$line['component_code']] ?? 0.0) + (float) $line['amount'];
        }

        return $totals;
    }

    private function recordFailure(PayrollRunPaid $event, string $reason): void
    {
        $failure = PayrollPostingFailure::query()->firstOrNew(['subject_type' => $event->subjectType, 'subject_id' => $event->subjectId]);
        if (! $failure->exists) {
            $failure->uuid = (string) Str::uuid();
        }
        $failure->fill([
            'company_id' => $event->companyId,
            'payload' => $this->payloadOf($event),
            'reason' => $reason,
            'status' => PayrollPostingFailure::STATUS_PENDING,
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
        $failure->save();
    }

    private function finalizePosting(PayrollRunPaid $event, GlJournal $journal): void
    {
        PayrollGlPosting::query()->create([
            'company_id' => $event->companyId,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'journal_id' => $journal->id,
        ]);

        PayrollPostingFailure::query()
            ->where('subject_type', $event->subjectType)
            ->where('subject_id', $event->subjectId)
            ->where('status', PayrollPostingFailure::STATUS_PENDING)
            ->update(['status' => PayrollPostingFailure::STATUS_RESOLVED, 'resolved_at' => now()]);
    }

    /** @return array{companyId:int, runDate:string, lines:array, subjectType:string, subjectId:string, memo:?string} */
    private function payloadOf(PayrollRunPaid $event): array
    {
        return [
            'companyId' => $event->companyId,
            'runDate' => $event->runDate,
            'lines' => $event->lines,
            'subjectType' => $event->subjectType,
            'subjectId' => $event->subjectId,
            'memo' => $event->memo,
        ];
    }
}
