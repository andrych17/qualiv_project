<?php

namespace App\Console\Commands;

use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Services\CentralAccessStatusCache;
use App\Modules\Central\Services\CentralAuditLogger;
use App\Modules\Central\Services\CentralDunningService;
use Illuminate\Console\Command;

/**
 * Daily soft-cutoff sweep (CENTRAL_SPECS.md §3G): flips a tenant to read_only once their
 * oldest unpaid issued invoice is past due_date + cutoff_days_after_due. Never touches data,
 * never re-flips a tenant already read_only. Reactivation happens automatically in
 * CentralPaymentService::confirm() — this command only ever moves a tenant toward read_only.
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
            ->where('status', 'issued')
            ->with('tenant')
            ->each(function (CentralInvoice $invoice) use ($today): void {
                $tenant = $invoice->tenant;

                if (! $tenant || $tenant->access_status === 'read_only') {
                    return;
                }

                $policy = $this->dunning->resolvePolicyFor($tenant);
                $cutoffDate = $invoice->due_date->copy()->addDays($policy->cutoff_days_after_due);

                if ($today->lessThan($cutoffDate)) {
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

        return self::SUCCESS;
    }
}
