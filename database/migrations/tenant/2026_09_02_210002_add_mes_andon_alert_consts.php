<?php

use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Database\Migrations\Migration;

/**
 * MES_SPECS.md §3R — customization ladder rung 1 (CLAUDE.md §2). `MAINTENANCE_CONTACT_ROLE`
 * (§3M) already covers who to notify for equipment-focused alerts (machine_stopped,
 * maintenance_required); this adds one more const for the remaining four alert types, which are
 * a production-supervisor concern rather than a maintenance one.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'MES', 'group_code' => 'ANDON_ALERT_ROLE'],
            [
                'seq' => 3,
                'value' => 'ADMIN',
                'value_type' => 'text',
                'note1' => '§3R: SYSCONFIG group role notified for material shortage / behind-schedule / overdue-batch / out-of-spec-parameter Andon alerts.',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
