<?php

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Migrations\Migration;

/**
 * MES_SPECS.md §3I — batch numbering series (`MES_BATCH_LASTID`), same auto-seeded pattern as
 * `MES_MO_LASTID` (2026_09_02_140002_add_mes_mo_lastid_snum.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigSnum::query()->updateOrCreate(
            ['code' => 'MES_BATCH_LASTID'],
            [
                'last_cnt' => 0,
                'wrap_low' => 1,
                'wrap_high' => 999999,
                'step_cnt' => 1,
                'descr' => 'MES batch running number',
                'status_code' => 'A',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
