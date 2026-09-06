<?php

namespace Tests\Feature;

use App\Modules\MES\Models\Routing;
use App\Modules\MES\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3E — discrete Routing/Operations; one active version per product. */
class MesRoutingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_admin_can_crud_a_routing_with_ops_and_only_one_stays_active_per_product(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productId = null;
        $productSku = null;
        $workCenterId = null;
        $tenant->run(function () use (&$productId, &$productSku, &$workCenterId) {
            $product = $this->makeProduct('RT-1');
            $productId = $product->id;
            $productSku = $product->sku;
            $workCenterId = $this->makeWorkCenter()->id;
        });

        $this->get('/mes/routings')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Routings/Index'));
        $this->get('/mes/routings/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Routings/Create'));

        $this->post('/mes/routings', [
            'product_id' => $productId, 'is_active' => true,
            'ops' => [
                ['op_code' => 'OP1', 'op_name' => 'Cut', 'work_center_id' => $workCenterId, 'run_time_minutes' => 10],
                ['op_code' => 'OP2', 'op_name' => 'Assemble', 'work_center_id' => $workCenterId, 'run_time_minutes' => 20],
            ],
        ])->assertRedirect(route('mes.routings.index'));

        $v1Id = null;
        $tenant->run(function () use (&$v1Id, $productId) {
            $v1 = Routing::query()->where('product_id', $productId)->where('version', 1)->firstOrFail();
            $v1Id = $v1->id;
            $this->assertSame(2, $v1->ops()->count());
        });

        $this->get('/mes/routings?search='.$productSku)->assertOk()
            ->assertInertia(fn ($page) => $page->has('routings.data', 1));

        $this->get("/mes/routings/{$v1Id}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Routings/Edit')->has('routing.ops', 2));

        // A second `is_active` version for the same product deactivates v1.
        $this->post('/mes/routings', [
            'product_id' => $productId, 'version' => 2, 'is_active' => true,
            'ops' => [['op_code' => 'OP1', 'op_name' => 'Cut v2', 'work_center_id' => $workCenterId]],
        ])->assertRedirect(route('mes.routings.index'));

        $v2Id = null;
        $tenant->run(function () use (&$v2Id, $productId, $v1Id) {
            $this->assertFalse(Routing::query()->find($v1Id)->is_active);
            $v2 = Routing::query()->where('product_id', $productId)->where('version', 2)->firstOrFail();
            $v2Id = $v2->id;
            $this->assertTrue($v2->is_active);
        });

        // Updating v1 to re-replace its ops (full replace-all semantics).
        $this->put("/mes/routings/{$v1Id}", [
            'is_active' => false,
            'ops' => [['op_code' => 'OP1-NEW', 'op_name' => 'Cut Renamed', 'work_center_id' => $workCenterId]],
        ])->assertRedirect(route('mes.routings.index'));

        $tenant->run(function () use ($v1Id) {
            $v1 = Routing::query()->find($v1Id);
            $this->assertSame(1, $v1->ops()->count());
            $this->assertSame('OP1-NEW', $v1->ops()->first()->op_code);
        });

        // Reactivating v1 deactivates v2.
        $this->put("/mes/routings/{$v1Id}", [
            'is_active' => true,
            'ops' => [['op_code' => 'OP1-NEW', 'op_name' => 'Cut Renamed', 'work_center_id' => $workCenterId]],
        ])->assertRedirect(route('mes.routings.index'));

        $tenant->run(function () use ($v1Id, $v2Id) {
            $this->assertTrue(Routing::query()->find($v1Id)->is_active);
            $this->assertFalse(Routing::query()->find($v2Id)->is_active);
        });

        $this->delete("/mes/routings/{$v2Id}")->assertRedirect(route('mes.routings.index'));
        $tenant->run(function () use ($v2Id) {
            $this->assertNull(Routing::query()->find($v2Id));
        });
    }

    public function test_routing_store_and_update_reject_invalid_product_and_work_center_references(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productId = null;
        $routingId = null;
        $tenant->run(function () use (&$productId, &$routingId) {
            $workCenter = $this->makeWorkCenter();
            $product = $this->makeProduct('RT-2');
            $productId = $product->id;
            $routing = $this->makeRouting($productId);
            $routingId = $routing->id;
            $this->makeRoutingOp($routing, $workCenter);
        });

        $this->post('/mes/routings', [
            'product_id' => 999999, 'ops' => [['op_code' => 'OP1', 'op_name' => 'X', 'work_center_id' => 999999]],
        ])->assertSessionHasErrors(['product_id', 'ops.0.work_center_id']);

        $this->post('/mes/routings', ['product_id' => $productId, 'ops' => []])
            ->assertSessionHasErrors(['ops']);

        $this->put("/mes/routings/{$routingId}", [
            'ops' => [['op_code' => 'OP1', 'op_name' => 'X', 'work_center_id' => 999999]],
        ])->assertSessionHasErrors(['ops.0.work_center_id']);
    }

    public function test_admin_can_bulk_destroy_routings(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = null;
        $tenant->run(function () use (&$ids) {
            $product = $this->makeProduct('RT-3');
            $ids = [
                $this->makeRouting($product->id, ['version' => 1])->id,
                $this->makeRouting($product->id, ['version' => 2, 'is_active' => false])->id,
            ];
        });

        $this->delete('/mes/routings/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () {
            $this->assertSame(0, Routing::query()->count());
        });
    }

    /** RoutingService::syncOps()'s per-op "skip if op_code/op_name/work_center_id blank" branch is shadowed by the FormRequest's own `required` rules — only reachable via a direct service call. */
    public function test_service_skips_an_incomplete_op_row_when_called_directly(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('RT-4');
            $workCenter = $this->makeWorkCenter();

            $routing = app(RoutingService::class)->create([
                'product_id' => $product->id,
                'ops' => [
                    ['op_code' => '', 'op_name' => '', 'work_center_id' => null],
                    ['op_code' => 'OP1', 'op_name' => 'Real Op', 'work_center_id' => $workCenter->id],
                ],
            ]);

            $this->assertSame(1, $routing->ops()->count());
            $this->assertSame('OP1', $routing->ops()->first()->op_code);
        });
    }
}
