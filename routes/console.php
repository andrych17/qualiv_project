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
