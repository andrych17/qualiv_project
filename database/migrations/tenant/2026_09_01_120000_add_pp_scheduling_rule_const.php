<?php

use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Database\Migrations\Migration;

/**
 * PP_SPECS.md §3I — the tenant-wide default dispatch strategy (customization ladder rung 1,
 * CLAUDE.md §2); the planner overrides it per scheduling run via ScheduleOpController's
 * applyStrategy action. No menu entry — §3I is an action on the existing /pp/schedule-ops page,
 * not a new page. Same pattern as 2026_08_31_200001_add_pp_capacity_const_menu_and_rights.php's
 * const block; SysConfigSeeder does not need a matching entry since a missing const already
 * falls back cleanly (see SchedulingRuleService's caller).
 */
return new class extends Migration
{
    public function up(): void
    {
        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'PP', 'group_code' => 'DEFAULT_SCHEDULING_STRATEGY'],
            [
                'seq' => 4,
                'value' => 'earliest_due_date',
                'value_type' => 'text',
                'note1' => 'Default §3I dispatch strategy; the planner may override it per scheduling run.',
            ],
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
