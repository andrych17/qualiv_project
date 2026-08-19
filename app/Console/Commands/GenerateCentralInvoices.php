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
 */
class GenerateCentralInvoices extends Command
{
    protected $signature = 'central:generate-invoices';

    protected $description = 'Generate this billing period\'s invoice for every tenant on a known plan.';

    public function handle(CentralInvoiceService $invoices): int
    {
        $generated = 0;
        $skipped = 0;
        $noPlan = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $plan = CentralPlan::query()->where('code', $tenant->plan)->first();

            if (! $plan) {
                $noPlan++;

                continue;
            }

            [$periodStart, $periodEnd] = $this->currentPeriod($tenant, $plan);
            $dueDate = today()->parse($periodEnd)->addDays(14)->toDateString();

            $invoice = $invoices->create($tenant, $plan, $periodStart, $periodEnd, $dueDate);

            $invoice ? $generated++ : $skipped++;
        }

        $this->info("Generated {$generated}, already billed {$skipped}, no matching plan {$noPlan}.");

        return self::SUCCESS;
    }

    /**
     * The billing period currently in force for this tenant. Monthly = calendar month.
     * Annual = the anniversary year anchored on the tenant's created_at month/day.
     *
     * ponytail: anniversary is approximated from tenants.created_at rather than a dedicated
     * subscription-start column — good enough while every tenant's created_at is (by
     * construction) their subscription start. Upgrade path: a real `subscription_started_at`
     * column if tenants ever get re-provisioned or their anchor needs to move.
     *
     * @return array{string, string} [period_start, period_end] as Y-m-d
     */
    private function currentPeriod(Tenant $tenant, CentralPlan $plan): array
    {
        if ($plan->billing_cycle !== 'annual') {
            return [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        }

        $anchor = $tenant->created_at ?? now();
        $thisYear = $anchor->copy()->setYear(now()->year);
        $start = $thisYear->isAfter(now()) ? $thisYear->copy()->subYear() : $thisYear;

        return [$start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }
}
