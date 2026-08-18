<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform_default central_dunning_policies row is required operational configuration —
 * CentralDunningService::resolvePolicyFor() throws when nothing resolves, which would hard-fail
 * the daily central:send-dunning-reminders / central:apply-dunning-cutoff commands in any
 * environment missing it. CentralSeeder also inserts this same row, but DatabaseSeeder guards
 * itself out of production entirely (app()->isProduction() check) before ever calling it — so
 * production would otherwise never get this row. A migration runs everywhere, seeders don't;
 * this is the one source guaranteed to exist after `php artisan migrate` regardless of
 * environment. CentralSeeder's own insert is left in place too (harmless updateOrCreate, still
 * useful for fresh dev installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('central_dunning_policies')->insertOrIgnore([
            'scope_type' => 'platform_default',
            'scope_id' => null,
            'reminder_offsets_days' => json_encode([-7, -3, -1, 3, 7]),
            'cutoff_days_after_due' => 14,
            'cutoff_action' => 'read_only',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('central_dunning_policies')
            ->where('scope_type', 'platform_default')
            ->whereNull('scope_id')
            ->delete();
    }
};
