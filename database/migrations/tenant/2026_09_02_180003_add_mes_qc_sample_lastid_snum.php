<?php

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Database\Migrations\Migration;

/**
 * MES_SPECS.md §3L — QC sample numbering series (`MES_QC_SAMPLE_LASTID`), same auto-seeded
 * pattern as `MES_MO_LASTID`/`MES_BATCH_LASTID`.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigSnum::query()->updateOrCreate(
            ['code' => 'MES_QC_SAMPLE_LASTID'],
            [
                'last_cnt' => 0,
                'wrap_low' => 1,
                'wrap_high' => 999999,
                'step_cnt' => 1,
                'descr' => 'MES QC sample running number',
                'status_code' => 'A',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
