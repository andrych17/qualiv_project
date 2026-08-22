<?php

namespace App\Console\Commands;

use App\Modules\DMS\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * DMS §3F retention sweep. Tenant-scoped — run per tenant via stancl's `tenants:run`,
 * same convention as wne:escalate-breached-workflow-steps/wne:recover-stuck-workflow-steps.
 */
class ApplyDmsRetentionPolicies extends Command
{
    protected $signature = 'dms:apply-retention-policies';

    protected $description = 'Scan documents at/past their expiry date and apply the configured retention action (§3F)';

    public function handle(RetentionService $retention): int
    {
        $summary = $retention->runDailySweep();

        if (array_sum($summary) > 0) {
            $this->info(sprintf(
                'DMS retention sweep: %d expired (notify), %d archived, %d delete-approval requested, %d held (skipped).',
                $summary['notified'],
                $summary['archived'],
                $summary['delete_requested'],
                $summary['held'],
            ));
        }

        return self::SUCCESS;
    }
}
