<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Services\CentralAccessStatusCache;
use App\Modules\Central\Services\CentralAuditLogger;
use App\Modules\Central\Services\CentralDunningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily soft-cutoff sweep (CENTRAL_SPECS.md §3G): recomputes the derived `overdue` invoice
 * status (§3E), then flips a tenant to read_only once their oldest unpaid invoice is past
 * due_date + cutoff_days_after_due. Never touches data, never re-flips a tenant already
 * read_only. Reactivation happens automatically in CentralPaymentService::confirm() — this
 * command only ever moves a tenant toward read_only.
 */
class ApplyDunningCutoff extends Command
{
    protected $signature = 'central:apply-dunning-cutoff';

    protected $description = 'Flip tenants with an invoice past their configured cutoff window to read_only access';

    public function __construct(
        protected CentralDunningService $dunning,
        protected CentralAuditLogger $auditLogger,
        protected CentralAccessStatusCache $cache,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = today();

        CentralInvoice::query()
            ->whereIn('status', ['issued', 'overdue'])
            ->with('tenant')
            ->orderBy('due_date')
            ->each(function (CentralInvoice $invoice) use ($today): void {
                $tenant = $invoice->tenant;

                if (! $tenant || $tenant->access_status === 'read_only') {
                    return;
                }

                // Derived `overdue` recompute (§3E) — the dunning sweep owns this transition.
                $invoice->markOverdueIfPastDue();

                $policy = $this->dunning->resolvePolicyFor($tenant);
                $cutoffDate = $invoice->due_date->copy()->addDays($policy->cutoff_days_after_due);

                if ($today->lessThan($cutoffDate)) {
                    return;
                }

                $this->cutOff($invoice, $tenant);
            });

        return self::SUCCESS;
    }

    /**
     * Lock + re-check inside a transaction so a payment confirmed between the outer query and
     * this write always wins (CENTRAL_SPECS.md §5): CentralPaymentService::confirm() locks the
     * same invoice row, so the two serialize — whichever lands second sees the other's outcome.
     */
    private function cutOff(CentralInvoice $invoice, Tenant $tenant): void
    {
        DB::transaction(function () use ($invoice, $tenant): void {
            $invoice = CentralInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, ['issued', 'overdue'], true)) {
                return; // Paid or voided while we were iterating — confirm() won the race.
            }

            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());

            if ($tenant->access_status === 'read_only') {
                return;
            }

            $before = $tenant->toArray();
            $tenant->update(['access_status' => 'read_only']);
            $this->cache->invalidate($tenant->getKey());

            $this->auditLogger->log(
                action: 'access_status_changed',
                entityType: 'tenant',
                entityId: $tenant->getKey(),
                before: $before,
                after: $tenant->refresh()->toArray(),
            );

            $this->info("Tenant {$tenant->getKey()} cut off to read_only (invoice #{$invoice->id} overdue).");
        });
    }
}
