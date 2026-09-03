<?php

use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Database\Migrations\Migration;

/**
 * MES_SPECS.md §3M — customization-ladder rung 1 (CLAUDE.md §2) for the auto-maintenance-request
 * rule: how long an unplanned downtime must stay open before it fires, and which role receives
 * the notification ("a simple WNE notification to the maintenance contact on file" — there is no
 * stored maintenance-contact concept in this build, so "on file" resolves to a role, same as
 * every other MVP recipient in this codebase, e.g. AchievementService's ADMIN broadcast).
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'MES', 'group_code' => 'DOWNTIME_THRESHOLD_MINUTES'],
            [
                'seq' => 1,
                'value' => '60',
                'value_type' => 'number',
                'note1' => '§3M: unplanned downtime open longer than this auto-fires a maintenance-request notification.',
            ],
        );

        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'MES', 'group_code' => 'MAINTENANCE_CONTACT_ROLE'],
            [
                'seq' => 2,
                'value' => 'ADMIN',
                'value_type' => 'text',
                'note1' => '§3M: SYSCONFIG group role that receives the auto-maintenance-request notification.',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
