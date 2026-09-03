<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnHdr;
use App\Modules\POS\Services\PosOfflineSyncService;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3S — Offline Transaction Sync & Idempotency Tests.
 */
class PosOfflineSyncTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_offline_sync_creates_transaction_and_is_idempotent(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Offline Warehouse', 'address' => 'Store']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-OFFLINE',
                'name' => 'Offline Register',
                'receipt_prefix' => 'TOFF',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 500000.0);
            $syncService = app(PosOfflineSyncService::class);

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'OFF-ITEM-01',
                'name' => 'Offline Item',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);

            $clientTxnUuid = (string) Str::uuid();

            $payload = [
                'client_txn_uuid' => $clientTxnUuid,
                'session_id' => $session->id,
                'receipt_number' => 'TOFF-000001',
                'subtotal' => 20000,
                'grand_total' => 20000,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'qty' => 1,
                        'unit_price' => 20000,
                        'line_total' => 20000,
                    ],
                ],
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 20000,
                    ],
                ],
            ];

            // 1. Initial sync
            $res1 = $syncService->syncTransaction($payload, $terminal->id);
            $this->assertEquals('synced', $res1['status']);
            $this->assertTrue((bool) $res1['transaction']->created_offline);
            $this->assertEquals(PosTxnHdr::STATUS_COMPLETED, $res1['transaction']->status);

            // 2. Idempotency test: Second sync of the exact same client_txn_uuid
            $res2 = $syncService->syncTransaction($payload, $terminal->id);
            $this->assertEquals('already_synced', $res2['status']);
            $this->assertEquals($res1['transaction']->id, $res2['transaction']->id);

            // Ensure only 1 record exists in database
            $count = PosTxnHdr::query()->where('client_txn_uuid', $clientTxnUuid)->count();
            $this->assertEquals(1, $count);
        });
    }
}
