<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3S: the payload shape Payroll will dispatch once its own engine ships, mirroring
 * `payroll.run_paid` (fired when a payroll run locks — `PAYROLL_SPECS.md` §3-Admin: "Flipping
 * to `paid` also fires `payroll.run_paid` ... carrying the run's per-component totals by
 * GL-relevant category"). Consumed by App\Modules\Accounting\Listeners\PostPayrollRunToGl.
 *
 * No real caller exists yet — the Payroll module has zero real code today (only scaffolding
 * folders and spec docs), same "engine ships before its caller" precedent as §3D's
 * InvoiceRequested and §3H's Inventory events.
 *
 * `lines` is pre-aggregated to RUN-LEVEL totals per component (Payroll sums across every
 * employee before firing this event, per its own spec text) — never per-employee detail, and
 * never a locally recalculated figure; this engine holds zero payroll calculation logic
 * (§3S rule). Each line names only its component_code and amount — which GL account(s) it
 * posts to, and whether it's an earning/deduction/employer_cost, comes from this module's OWN
 * configured mapping (PayrollComponentGlMapping), not from the event, so Accounting doesn't
 * trust an ad-hoc classification handed to it per run.
 *
 * @param  list<array{component_code:string, amount:float}>  $lines
 */
class PayrollRunPaid
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public string $runDate,
        public array $lines,
        public string $subjectType,
        public string $subjectId,
        public ?string $memo = null,
    ) {}
}
