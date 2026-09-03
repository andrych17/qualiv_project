<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosReturnHdr;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Services\PosCartService;
use App\Modules\POS\Services\PosPaymentService;
use App\Modules\POS\Services\PosReturnService;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3L — POS Return & Refund Tests.
 */
class PosReturnTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_return_processing_with_automatic_stock_reversal(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Return Warehouse', 'address' => 'Store']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-RET',
                'name' => 'Return Terminal',
                'receipt_prefix' => 'TRET',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 200000.0);
            $cartService = app(PosCartService::class);
            $paymentService = app(PosPaymentService::class);
            $returnService = app(PosReturnService::class);

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'RET-PROD',
                'name' => 'Returnable Item',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);

            // Complete a sale first
            $txn = $cartService->createDraftTransaction($session->id);
            $line = $cartService->addLine($txn, [
                'product_id' => $product->id,
                'unit_price' => 25000,
                'qty' => 2,
            ]);
            $paymentService->addPayment($txn->id, 'cash', 50000.0);
            $paymentService->completeTransaction($txn->id);

            // Process Return: 1 unit restockable
            $return = $returnService->processReturn(
                $txn->id,
                $session->id,
                'DEFECTIVE_OR_UNWANTED',
                [
                    [
                        'original_txn_line_id' => $line->id,
                        'qty' => 1,
                        'unit_price' => 25000,
                        'condition_note' => 'Unopened box',
                        'restockable' => true,
                    ],
                ],
                'cash'
            );

            $this->assertEquals(PosReturnHdr::STATUS_COMPLETED, $return->status);
            $this->assertCount(1, $return->lines);
            $this->assertEquals(1.0, (float) $return->lines->first()->qty);
            $this->assertTrue((bool) $return->lines->first()->restockable);
        });
    }
}
