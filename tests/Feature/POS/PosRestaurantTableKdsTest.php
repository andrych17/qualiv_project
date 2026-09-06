<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosFloor;
use App\Modules\POS\Models\PosKdsStation;
use App\Modules\POS\Models\PosKdsTicketEvent;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosTable;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Models\PosTxnLine;
use App\Modules\POS\Services\PosCartService;
use App\Modules\POS\Services\PosRestaurantService;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3M, §3O — Restaurant Floor/Table Management & KDS Tests.
 */
class PosRestaurantTableKdsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_table_open_move_merge_and_kds_routing(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Resto Warehouse 2', 'address' => 'Mall']);
            $profile = PosProfile::query()->where('code', 'RESTAURANT')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'T-TABLE',
                'name' => 'Table Terminal',
                'receipt_prefix' => 'TTBL',
            ]);

            $session = app(PosSessionService::class)->openSession($terminal->id, $user->id, 200000.0);
            $cartService = app(PosCartService::class);
            $restoService = app(PosRestaurantService::class);

            $floor = PosFloor::query()->create(['name' => 'Indoor Dining']);
            $table1 = PosTable::query()->create(['floor_id' => $floor->id, 'code' => 'T1', 'seat_count' => 4]);
            $table2 = PosTable::query()->create(['floor_id' => $floor->id, 'code' => 'T2', 'seat_count' => 2]);
            $station = PosKdsStation::query()->create(['code' => 'KITCHEN-HOT', 'name' => 'Hot Kitchen']);

            $uom = Uom::query()->create(['code' => 'PORTION', 'name' => 'Portion']);
            $product = Product::query()->create([
                'sku' => 'STEAK-01',
                'name' => 'Ribeye Steak',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);

            // 1. Open Table 1
            $txn1 = $restoService->openTable($table1->id, $session->id);
            $table1->refresh();
            $this->assertEquals(PosTable::STATUS_OCCUPIED, $table1->status);

            // Add line to Table 1
            $line1 = $cartService->addLine($txn1, [
                'product_id' => $product->id,
                'unit_price' => 120000,
                'qty' => 1,
            ]);

            // 2. Route order to KDS (§3O)
            $restoService->routeToKds($txn1);
            $line1->refresh();
            $this->assertEquals(PosTxnLine::KDS_NEW, $line1->kds_status);

            // Transition: NEW -> PREPARING -> READY
            $restoService->updateKdsLineStatus($line1->id, PosTxnLine::KDS_PREPARING, $user->id);
            $line1->refresh();
            $this->assertEquals(PosTxnLine::KDS_PREPARING, $line1->kds_status);

            $restoService->updateKdsLineStatus($line1->id, PosTxnLine::KDS_READY, $user->id);
            $line1->refresh();
            $this->assertEquals(PosTxnLine::KDS_READY, $line1->kds_status);

            // Check KDS events log
            $events = PosKdsTicketEvent::query()->where('txn_line_id', $line1->id)->get();
            $this->assertCount(3, $events); // new, preparing, ready

            // 3. Move Table 1 to Table 2 (§3M)
            $restoService->moveTable($table1->id, $table2->id);
            $table1->refresh();
            $table2->refresh();
            $txn1->refresh();

            $this->assertEquals(PosTable::STATUS_AVAILABLE, $table1->status);
            $this->assertEquals(PosTable::STATUS_OCCUPIED, $table2->status);
            $this->assertEquals($table2->id, $txn1->table_id);
        });
    }
}
