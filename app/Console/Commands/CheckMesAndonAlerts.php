<?php

namespace App\Console\Commands;

use App\Modules\MES\Services\AndonService;
use Illuminate\Console\Command;

/**
 * MES_SPECS.md §3R alert sweep. Tenant-scoped — run per tenant via stancl's `tenants:run`, same
 * convention as `mes:check-downtime-thresholds`.
 */
class CheckMesAndonAlerts extends Command
{
    protected $signature = 'mes:check-andon-alerts';

    protected $description = 'Detect and notify the six Andon alert conditions, and auto-resolve any that cleared (§3R)';

    public function handle(AndonService $andon): int
    {
        $fired = $andon->checkAndFireAlerts();

        if ($fired > 0) {
            $this->info("Fired {$fired} new Andon alert(s).");
        }

        return self::SUCCESS;
    }
}
