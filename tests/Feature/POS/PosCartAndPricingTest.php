<?php

namespace App\Modules\POS\Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosModifier;
use App\Modules\POS\Models\PosModifierGroup;
use App\Modules\POS\Models\PosOverrideLog;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Models\PosWeightedBarcodeTemplate;
use App\Modules\POS\Services\PosCartService;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3E, §3F, §3G, §3H — Cart, Barcode Scanning, Modifiers & Discounts Tests.
 */
class PosCartAndPricingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_barcode_scanning_supports_primary_case_pack_and_weighted_produce(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Store Warehouse', 'address' => 'Store']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-SCAN',
                'name' => 'Scanner Terminal',
                'receipt_prefix' => 'TSCAN',
            ]);

            $uomEa = Uom::query()->create(['code' => 'EA', 'name' => 'Each']);
            $uomKg = Uom::query()->create(['code' => 'KG', 'name' => 'Kilogram']);

            // Product 1: Bottled Water (single + case-pack)
            $water = Product::query()->create([
                'sku' => 'AQUA-600',
                'name' => 'Aqua 600ml',
                'base_uom_id' => $uomEa->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
                'standard_cost' => 3000,
            ]);

            DB::table('INVENTORY.product_barcodes')->insert([
                ['product_id' => $water->id, 'barcode' => '89910001', 'type' => 'primary', 'unit_multiplier' => 1],
                ['product_id' => $water->id, 'barcode' => '89910024', 'type' => 'case_pack', 'unit_multiplier' => 24],
            ]);

            // Product 2: Banana (weighted produce)
            $banana = Product::query()->create([
                'sku' => '04011',
                'name' => 'Fresh Banana',
                'base_uom_id' => $uomKg->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
                'standard_cost' => 25000,
            ]);

            // Weighted template: prefix 20..29, item code start 2 len 5, value start 7 len 5, weight 3 decimals
            PosWeightedBarcodeTemplate::query()->create([
                'name' => 'Scale Label Produce',
                'prefix_from' => '20',
                'prefix_to' => '29',
                'item_code_start' => 2,
                'item_code_length' => 5,
                'value_start' => 7,
                'value_length' => 5,
                'value_type' => 'weight',
                'decimal_places' => 3,
                'is_active' => true,
            ]);

            $cartService = app(PosCartService::class);

            // 1. Scan primary barcode (1 bottle)
            $resPrimary = $cartService->scanBarcode('89910001', $terminal->id);
            $this->assertEquals($water->id, $resPrimary['product']->id);
            $this->assertEquals(1.0, $resPrimary['qty']);

            // 2. Scan case-pack barcode (24 bottles)
            $resCasePack = $cartService->scanBarcode('89910024', $terminal->id);
            $this->assertEquals($water->id, $resCasePack['product']->id);
            $this->assertEquals(24.0, $resCasePack['qty']);

            // 3. Scan scale-labeled produce (weight embedded): "2004011003509" -> SKU 04011, weight 0.350 kg
            $resWeighted = $cartService->scanBarcode('2004011003509', $terminal->id);
            $this->assertEquals($banana->id, $resWeighted['product']->id);
            $this->assertEquals(0.350, $resWeighted['qty']);
            $this->assertTrue($resWeighted['is_weighted']);
        });
    }

    public function test_cart_lines_modifiers_and_discounts_with_override_audit(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Resto Warehouse', 'address' => 'Mall']);
            $profile = PosProfile::query()->where('code', 'RESTAURANT')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-RESTO',
                'name' => 'Resto POS',
                'receipt_prefix' => 'RESTO',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 100000.0);
            $cartService = app(PosCartService::class);

            $uom = Uom::query()->create(['code' => 'PORTION', 'name' => 'Portion']);
            $nasgor = Product::query()->create([
                'sku' => 'NASGOR-01',
                'name' => 'Nasi Goreng Spesial',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
                'standard_cost' => 35000,
            ]);

            // Modifiers: Spice Level
            $group = PosModifierGroup::query()->create([
                'name' => 'Spice Level',
                'selection_type' => 'single',
                'min_selections' => 1,
                'max_selections' => 1,
            ]);
            $group->products()->attach($nasgor->id);

            $modMild = PosModifier::query()->create(['group_id' => $group->id, 'name' => 'Mild', 'price_delta' => 0]);
            $modExtraSpicy = PosModifier::query()->create(['group_id' => $group->id, 'name' => 'Extra Spicy', 'price_delta' => 5000]);

            // Create draft transaction
            $txn = $cartService->createDraftTransaction($session->id, [
                'dining_mode' => PosTxnHdr::DINING_DINE_IN,
            ]);
            $this->assertStringStartsWith('RESTO-', $txn->receipt_number);

            // Add line with modifier (35000 base + 5000 Extra Spicy = 40000 per qty)
            $line = $cartService->addLine($txn, [
                'product_id' => $nasgor->id,
                'unit_price' => 35000,
                'qty' => 2,
                'modifier_ids' => [$modExtraSpicy->id],
            ]);

            $this->assertEquals(40000.0, (float) $line->unit_price);
            $this->assertEquals(80000.0, (float) $line->line_total);

            $txn->refresh();
            $this->assertEquals(80000.0, (float) $txn->subtotal);
            $this->assertEquals(80000.0, (float) $txn->grand_total);

            // Apply 20% discount (16,000) -> above 10% threshold -> requires supervisor PIN
            try {
                $cartService->applyHeaderDiscount($txn, 16000.0, null, $user->id);
                $this->fail('Expected ValidationException when discount exceeds threshold without PIN');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('discount', $e->errors());
            }

            // With supervisor PIN '1234'
            $cartService->applyHeaderDiscount($txn, 16000.0, '1234', $user->id);
            $txn->refresh();
            $this->assertEquals(16000.0, (float) $txn->discount_total);
            $this->assertEquals(64000.0, (float) $txn->grand_total);

            // Verify PosOverrideLog was recorded (§3T)
            $log = PosOverrideLog::query()->where('txn_id', $txn->id)->first();
            $this->assertNotNull($log);
            $this->assertEquals(PosOverrideLog::ACTION_DISCOUNT, $log->action_type);

            // Park and resume order (§3F)
            $parked = $cartService->parkTransaction($txn->id, 'Table 5 Waiter Ani');
            $this->assertEquals(PosTxnHdr::STATUS_PARKED, $parked->status);
            $this->assertEquals('Table 5 Waiter Ani', $parked->park_label);

            $resumed = $cartService->resumeTransaction($txn->id);
            $this->assertEquals(PosTxnHdr::STATUS_DRAFT, $resumed->status);
        });
    }
}
