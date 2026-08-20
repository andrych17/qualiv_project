<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CENTRAL_SPECS.md §3E — one recurring invoice per tenant per billing cycle.
Schedule::command('central:generate-invoices')->monthlyOn(1, '01:00');

// CENTRAL_SPECS.md §3G — same daily run: reminders first, then the soft-cutoff sweep.
Schedule::command('central:send-dunning-reminders')->dailyAt('07:00');
Schedule::command('central:apply-dunning-cutoff')->dailyAt('07:15');

// WNE_SPECS.md §3C recovery sweep — tenant-scoped, run per tenant via stancl's tenants:run.
Schedule::command('tenants:run "wne:recover-stuck-workflow-steps"')->everyFiveMinutes();

// WNE_SPECS.md §3F SLA escalation sweep — same tenant-scoped convention as the recovery sweep.
Schedule::command('tenants:run "wne:escalate-breached-workflow-steps"')->everyFiveMinutes();
