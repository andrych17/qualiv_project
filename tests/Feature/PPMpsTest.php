<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
use App\Modules\PP\Models\MpsHeader;
use App\Modules\PP\Models\MpsLine;
use App\Modules\PP\Models\PlannedOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3C — Master Production Schedule grid: add/remove a product, edit + freeze-lock
 * a period's quantity, and the firm/MRP interaction (advisor-flagged correctness case: firming
 * a period's planned order must exclude it from MrpService's regenerative delete while still
 * preserving its dependent demand on downstream components).
 */
class PPMpsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_add_and_remove_product_from_mps_grid(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'MPS-01', 'name' => 'MPS Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
        });

        $this->post('/pp/mps', ['product_id' => $productId])->assertRedirect('/pp/mps');

        $headerId = null;
        $tenant->run(function () use (&$headerId, $productId) {
            $header = MpsHeader::query()->where('product_id', $productId)->first();
            $this->assertNotNull($header);
            $this->assertSame(8, $header->lines()->count());
            $headerId = $header->id;
        });

        $this->get('/pp/mps')->assertOk()->assertInertia(fn ($page) => $page
            ->component('PP/Mps/Index')
            ->has('rows', 1)
            ->has('periods', 8));

        $this->delete("/pp/mps/{$headerId}")->assertRedirect('/pp/mps');
        $tenant->run(function () use ($headerId) {
            $this->assertNull(MpsHeader::query()->find($headerId));
        });
    }

    public function test_freeze_locks_planned_qty_edits(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'MPS-02', 'name' => 'MPS Widget 2', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
        });

        $this->post('/pp/mps', ['product_id' => $productId])->assertRedirect('/pp/mps');

        $lineId = null;
        $tenant->run(function () use (&$lineId, $productId) {
            $lineId = MpsHeader::query()->where('product_id', $productId)->first()->lines()->first()->id;
        });

        $this->patch("/pp/mps/lines/{$lineId}", ['planned_qty' => 500])->assertRedirect();
        $tenant->run(function () use ($lineId) {
            $this->assertEquals(500, MpsLine::query()->find($lineId)->planned_qty);
        });

        $this->patch("/pp/mps/lines/{$lineId}/freeze")->assertRedirect();
        $tenant->run(function () use ($lineId) {
            $this->assertTrue(MpsLine::query()->find($lineId)->is_frozen);
        });

        $this->patch("/pp/mps/lines/{$lineId}", ['planned_qty' => 999])->assertSessionHasErrors('planned_qty');
        $tenant->run(function () use ($lineId) {
            $this->assertEquals(500, MpsLine::query()->find($lineId)->planned_qty);
        });
    }

    public function test_firming_a_period_excludes_its_planned_order_from_mrp_regeneration(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 7, 9, 0, 0));

        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $fgId = null;
        $rmId = null;
        $tenant->run(function () use (&$fgId, &$rmId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $fg = Product::query()->create([
                'sku' => 'MPS-FG-01', 'name' => 'MPS Finished Good', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $rm = Product::query()->create([
                'sku' => 'MPS-RM-01', 'name' => 'MPS Raw Material', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $fgId = $fg->id;
            $rmId = $rm->id;

            $bom = Bom::query()->create(['product_id' => $fg->id, 'version' => 1, 'is_active' => true]);
            BomLine::query()->create(['bom_id' => $bom->id, 'component_product_id' => $rm->id, 'qty_per_parent_unit' => 2, 'scrap_pct' => 0]);
        });

        $this->post('/pp/mps', ['product_id' => $fgId])->assertRedirect('/pp/mps');

        // Week 0 starts "today" (Monday); the demand's need_by lands exactly on week-1's start.
        $weekOneStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeek();

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $fgId, 'need_by_date' => $weekOneStart->toDateString(), 'qty' => 10]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $fgOrderId = null;
        $fgPlanNumber = null;
        $mpsLineId = null;
        $tenant->run(function () use (&$fgOrderId, &$fgPlanNumber, &$mpsLineId, $fgId, $weekOneStart) {
            $fgOrder = PlannedOrder::query()->where('product_id', $fgId)->first();
            $this->assertNotNull($fgOrder);
            $this->assertEquals(10, $fgOrder->qty);
            $fgOrderId = $fgOrder->id;
            $fgPlanNumber = $fgOrder->plan_number;

            $mpsLine = MpsLine::query()
                ->where('period_start', $weekOneStart->toDateString())
                ->first();
            $this->assertNotNull($mpsLine);
            $mpsLineId = $mpsLine->id;
        });

        $this->patch("/pp/mps/lines/{$mpsLineId}/firm")->assertRedirect();
        $tenant->run(function () use ($fgOrderId) {
            $this->assertSame(PlannedOrder::STATUS_FIRMED, PlannedOrder::query()->find($fgOrderId)->status);
        });

        // Re-run MRP: the firmed FG order must survive untouched (same row, same qty), and its
        // dependent demand on the raw material must still be there (this is the case an
        // unguarded "skip creating a new row" fix would silently break).
        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($fgId, $rmId, $fgOrderId, $fgPlanNumber) {
            $this->assertSame(1, PlannedOrder::query()->where('product_id', $fgId)->count());
            $fgOrder = PlannedOrder::query()->find($fgOrderId);
            $this->assertNotNull($fgOrder);
            $this->assertSame(PlannedOrder::STATUS_FIRMED, $fgOrder->status);
            $this->assertEquals(10, $fgOrder->qty);
            $this->assertSame($fgPlanNumber, $fgOrder->plan_number);

            $rmOrder = PlannedOrder::query()->where('product_id', $rmId)->first();
            $this->assertNotNull($rmOrder, 'Dependent demand on the raw material must survive a firmed parent.');
            $this->assertSame(PlannedOrder::TYPE_PURCHASE, $rmOrder->order_type);
            $this->assertEquals(20, $rmOrder->qty);
        });
    }
}
