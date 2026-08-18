<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Services\CentralInvoiceService;
use Illuminate\Console\Command;

/**
 * Recurring billing job (CENTRAL_SPECS.md §3E) — one invoice per tenant per billing cycle,
 * itemized from the plan + active add-ons currently in force. Idempotent: a period already
 * billed for a tenant is skipped, never duplicated (enforced by CentralInvoiceService::create()
 * plus a DB unique constraint).
 *
 * ponytail: every plan is billed monthly for now — central_plans has no annual price/cycle
 * column yet (CENTRAL_SPECS.md §3D mentions price_annual but it was never added to the
 * migration), so annual billing is deferred until that column exists rather than invented here.
 */
class GenerateCentralInvoices extends Command
{
    protected $signature = 'central:generate-invoices';

    protected $description = 'Generate this billing period\'s invoice for every tenant on a known plan.';

    public function handle(CentralInvoiceService $invoices): int
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();
        $dueDate = now()->endOfMonth()->addDays(14)->toDateString();

        $generated = 0;
        $skipped = 0;
        $noPlan = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $plan = CentralPlan::query()->where('code', $tenant->plan)->first();

            if (! $plan) {
                $noPlan++;

                continue;
            }

            $invoice = $invoices->create($tenant, $plan, $periodStart, $periodEnd, $dueDate);

            $invoice ? $generated++ : $skipped++;
        }

        $this->info("Generated {$generated}, already billed {$skipped}, no matching plan {$noPlan}.");

        return self::SUCCESS;
    }
}
