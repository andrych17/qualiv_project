<?php

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:sync-lookups', function () {
    $this->info('Scanning all tenant databases to synchronize tenant_user_lookups...');
    $tenants = Tenant::all();
    $count = 0;

    foreach ($tenants as $tenant) {
        $tenant->run(function () use ($tenant, &$count) {
            $users = User::query()->whereNotNull('email')->get();
            foreach ($users as $user) {
                $email = strtolower(trim($user->email));
                if ($email !== '') {
                    TenantUserLookup::query()->updateOrCreate(
                        ['email' => $email, 'tenant_id' => (string) $tenant->getKey()],
                        [],
                    );
                    $count++;
                }
            }
        });
    }

    $this->info("Successfully synchronized {$count} user-tenant lookup entries.");
})->purpose('Synchronize central tenant_user_lookups from all tenant database users');

// CENTRAL_SPECS.md §3E — one recurring invoice per tenant per billing cycle.
Schedule::command('central:generate-invoices')->monthlyOn(1, '01:00');

// CENTRAL_SPECS.md §3G — same daily run: reminders first, then the soft-cutoff sweep.
Schedule::command('central:send-dunning-reminders')->dailyAt('07:00');
Schedule::command('central:apply-dunning-cutoff')->dailyAt('07:15');

// WNE_SPECS.md §3C recovery sweep — tenant-scoped, run per tenant via stancl's tenants:run.
Schedule::command('tenants:run "wne:recover-stuck-workflow-steps"')->everyFiveMinutes();

// WNE_SPECS.md §3F SLA escalation sweep — same tenant-scoped convention as the recovery sweep.
Schedule::command('tenants:run "wne:escalate-breached-workflow-steps"')->everyFiveMinutes();

// DMS_SPECS.md §3F retention sweep — daily, not every-five-minutes (expiry is date-grained, not
// time-sensitive like an SLA breach), same tenant-scoped tenants:run convention as WNE's above.
Schedule::command('tenants:run "dms:apply-retention-policies"')->dailyAt('02:00');

// ACCOUNTING_SPECS.md §3P recurring transactions sweep — date-grained like DMS retention
// above, same tenant-scoped tenants:run convention.
Schedule::command('tenants:run "accounting:run-recurring-sweep"')->dailyAt('03:00');

// INVENTORY_SPECS.md §3N reservation auto-release — housekeeping only (ATP is always
// computed live regardless of sweep timing, see ReservationService::activeReservedQty()),
// hourly is plenty given the default hold window is measured in hours, not minutes.
Schedule::command('tenants:run "inventory:release-expired-reservations"')->hourly();

// MES_SPECS.md §3M downtime-threshold sweep — same tenant-scoped tenants:run convention and
// cadence as WNE's SLA escalation sweep, since both are "notify once, past a threshold" rules.
Schedule::command('tenants:run "mes:check-downtime-thresholds"')->everyFiveMinutes();

// MES_SPECS.md §3R Andon alert sweep — same posture, five conditions computed fresh each run,
// idempotent via mes_andon_alerts' open-row uniqueness.
Schedule::command('tenants:run "mes:check-andon-alerts"')->everyFiveMinutes();
