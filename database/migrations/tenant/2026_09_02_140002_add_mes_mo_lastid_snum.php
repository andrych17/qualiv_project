<?php

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Migrations\Migration;

/**
 * MES_SPECS.md §3A/§5 — MES's own production-order numbering series (`MES_MO_LASTID`),
 * distinct from PP's `PP_PLAN_LASTID`. Auto-seeded like PP's own snum
 * (2026_08_31_170002_add_pp_plan_lastid_snum.php), so a `full`-plan tenant can create a
 * production order out of the box without an extra admin setup step.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigSnum::query()->updateOrCreate(
            ['code' => 'MES_MO_LASTID'],
            [
                'last_cnt' => 0,
                'wrap_low' => 1,
                'wrap_high' => 999999,
                'step_cnt' => 1,
                'descr' => 'MES production order running number',
                'status_code' => 'A',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
