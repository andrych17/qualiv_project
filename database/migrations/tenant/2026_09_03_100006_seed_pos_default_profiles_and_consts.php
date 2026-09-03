<?php

use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS module — default POS Profiles and SYSCONFIG constants (§3A, §3C, §3K, §3R, §3T).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Default POS profiles (§3A)
        DB::table('POS.pos_profiles')->updateOrInsert(
            ['code' => 'CONVENIENCE'],
            [
                'name' => 'Convenience Store',
                'base_type' => 'retail',
                'requires_barcode' => true,
                'touch_menu' => false,
                'multi_uom' => true,
                'batch_expiry_tracking' => false,
                'weight_scale' => false,
                'customer_required' => false,
                'loyalty_enabled' => true,
                'promotion_enabled' => true,
                'table_management' => false,
                'modifiers_enabled' => false,
                'kds_enabled' => false,
                'recipe_consumption' => false,
                'delivery_enabled' => false,
                'offline_enabled' => true,
                'multi_branch' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('POS.pos_profiles')->updateOrInsert(
            ['code' => 'RESTAURANT'],
            [
                'name' => 'Restaurant',
                'base_type' => 'restaurant',
                'requires_barcode' => false,
                'touch_menu' => true,
                'multi_uom' => true,
                'batch_expiry_tracking' => false,
                'weight_scale' => false,
                'customer_required' => false,
                'loyalty_enabled' => true,
                'promotion_enabled' => true,
                'table_management' => true,
                'modifiers_enabled' => true,
                'kds_enabled' => true,
                'recipe_consumption' => true,
                'delivery_enabled' => false,
                'offline_enabled' => true,
                'multi_branch' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Default Config Constants (§3C, §3K, §3R, §3T)
        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'POS', 'group_code' => 'POS_ALLOW_OVERSELL'],
            [
                'seq' => 1,
                'value' => 'Y',
                'value_type' => 'text',
                'note1' => '§3K: Allow negative inventory on POS sales, posting variance for audit review.',
            ]
        );

        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'POS', 'group_code' => 'POS_DISCOUNT_PIN_ABOVE'],
            [
                'seq' => 2,
                'value' => '10',
                'value_type' => 'number',
                'note1' => '§3T: Percentage discount above which cashier requires supervisor PIN override.',
            ]
        );

        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'POS', 'group_code' => 'POS_OFFLINE_REDEMPTION_ALLOWED'],
            [
                'seq' => 3,
                'value' => 'N',
                'value_type' => 'text',
                'note1' => '§3R: Whether gift card/store credit/loyalty redemption is allowed while terminal is offline.',
            ]
        );

        ConfigConst::query()->updateOrCreate(
            ['const_group' => 'POS', 'group_code' => 'POS_CASH_VARIANCE_THRESHOLD'],
            [
                'seq' => 4,
                'value' => '50000',
                'value_type' => 'number',
                'note1' => '§3C: Cash variance amount requiring supervisor approval on shift close.',
            ]
        );
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
