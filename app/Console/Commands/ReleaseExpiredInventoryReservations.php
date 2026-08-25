<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * §3N auto-release sweep. Tenant-scoped — run per tenant via stancl's `tenants:run`, same
 * convention as the WNE/DMS/Accounting sweeps. Housekeeping only: `ReservationService::
 * activeReservedQty()` already treats an expired-but-still-`active` row as inactive at every
 * ATP read, so this command never gates correctness — it just flips `status` to `released`
 * for reporting/audit accuracy between ticks.
 */
class ReleaseExpiredInventoryReservations extends Command
{
    protected $signature = 'inventory:release-expired-reservations';

    protected $description = 'Release stock reservations (§3N) past their expiry that were never fulfilled';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->releaseExpired();

        $this->info("Released {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}
