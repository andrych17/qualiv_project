<?php

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Migrations\Migration;

/**
 * PP_SPECS.md §3D/§5 — PP's own planned-order numbering series, distinct from MES's (future)
 * MES_MO_LASTID. Auto-seeded (unlike Legal's LEGAL_MATTER_LASTID, which is left to manual
 * tenant setup via Config > Serials) so a 'full'-plan tenant can create a planned order
 * out of the box the first time MRP runs, without an extra admin setup step first.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigSnum::query()->updateOrCreate(
            ['code' => 'PP_PLAN_LASTID'],
            [
                'last_cnt' => 0,
                'wrap_low' => 1,
                'wrap_high' => 999999,
                'step_cnt' => 1,
                'descr' => 'PP planned order running number',
                'status_code' => 'A',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
