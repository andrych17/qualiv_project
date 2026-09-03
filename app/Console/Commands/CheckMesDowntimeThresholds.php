<?php

namespace App\Console\Commands;

use App\Modules\MES\Services\DowntimeService;
use Illuminate\Console\Command;

/**
 * MES_SPECS.md §3M threshold sweep. Tenant-scoped — run per tenant via stancl's `tenants:run`,
 * same convention as wne:escalate-breached-workflow-steps.
 */
class CheckMesDowntimeThresholds extends Command
{
    protected $signature = 'mes:check-downtime-thresholds';

    protected $description = 'Notify the maintenance contact role for unplanned downtime past the configured threshold (§3M)';

    public function handle(DowntimeService $downtime): int
    {
        $notified = $downtime->checkOpenThresholds();

        if ($notified > 0) {
            $this->info("Notified {$notified} threshold-exceeding downtime event(s).");
        }

        return self::SUCCESS;
    }
}
