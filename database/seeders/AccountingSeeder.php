<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * ACCOUNTING module — currencies lookup only (§3C's gl_journals FK dependency).
 * Tenant-wide, not company-scoped (see ACCOUNTING_SPECS.sql header note). Full
 * multi-currency management (§3L: enable/disable, exchange rates) is a later build.
 */
class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['code' => 'IDR', 'name' => 'Indonesian Rupiah'], ['code' => 'USD', 'name' => 'US Dollar']] as $row) {
            Currency::query()->updateOrCreate(['code' => $row['code']], ['name' => $row['name'], 'is_enabled' => true]);
        }
    }
}
