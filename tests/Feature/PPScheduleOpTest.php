<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\ScheduleOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3H — Detailed Scheduling: CRUD, the production-order-only rule, finite-capacity
 * conflict checking on commit (drafts never conflict), split/merge (§3H/§3K), and release
 * delegating to PlannedOrderService::release() (currently always MES-blocked — §7 Open Items).
 */
class PPScheduleOpTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function seedProductionOrder($tenant, string $planNumber): int
    {
        $orderId = null;
        $tenant->run(function () use (&$orderId, $planNumber) {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => "SCH-{$planNumber}", 'name' => 'Schedulable Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            $order = PlannedOrder::query()->create([
                'plan_number' => $planNumber,
                'order_type' => PlannedOrder::TYPE_PRODUCTION,
                'product_id' => $product->id,
                'qty' => 10,
                'need_by_date' => now()->addDays(14)->toDateString(),
                'bom_id' => $bom->id,
                'status' => PlannedOrder::STATUS_PLANNED,
            ]);
            $orderId = $order->id;
        });

        return $orderId;
    }

    private function seedPurchaseOrder($tenant, string $planNumber): int
    {
        $orderId = null;
        $tenant->run(function () use (&$orderId, $planNumber) {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => "PUR-{$planNumber}", 'name' => 'Purchased Part', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);

            $order = PlannedOrder::query()->create([
                'plan_number' => $planNumber,
                'order_type' => PlannedOrder::TYPE_PURCHASE,
                'product_id' => $product->id,
                'qty' => 10,
                'need_by_date' => now()->addDays(14)->toDateString(),
                'status' => PlannedOrder::STATUS_PLANNED,
            ]);
            $orderId = $order->id;
        });

        return $orderId;
    }

    public function test_only_production_orders_are_schedulable_and_crud_works(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $prodOrderId = $this->seedProductionOrder($tenant, 'PLN-SCH-000001');
        $purOrderId = $this->seedPurchaseOrder($tenant, 'PLN-SCH-000002');

        // A purchase-type order has no operations to schedule.
        $this->post('/pp/schedule-ops', [
            'planned_order_id' => $purOrderId,
            'planned_start' => '2026-09-22 08:00:00',
            'planned_end' => '2026-09-22 12:00:00',
        ])->assertSessionHasErrors('planned_order_id');

        $this->post('/pp/schedule-ops', [
            'planned_order_id' => $prodOrderId,
            'seq' => 1,
            'resource_type' => 'mes_work_center',
            'resource_ref_id' => 201,
            'planned_start' => '2026-09-22 08:00:00',
            'planned_end' => '2026-09-22 17:00:00',
        ])->assertRedirect('/pp/schedule-ops');

        $opId = null;
        $tenant->run(function () use (&$opId, $prodOrderId) {
            $op = ScheduleOp::query()->where('planned_order_id', $prodOrderId)->first();
            $this->assertNotNull($op);
            $this->assertSame(ScheduleOp::STATUS_DRAFT, $op->status);
            $opId = $op->id;
        });

        $this->get('/pp/schedule-ops')->assertOk()->assertInertia(fn ($page) => $page
            ->component('PP/ScheduleOps/Index')
            ->where('ops.data.0.status', 'draft')
        );

        // Move the op — change its window.
        $this->put("/pp/schedule-ops/{$opId}", [
            'seq' => 1,
            'resource_type' => 'mes_work_center',
            'resource_ref_id' => 201,
            'planned_start' => '2026-09-23 08:00:00',
            'planned_end' => '2026-09-23 17:00:00',
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($opId) {
            $op = ScheduleOp::query()->find($opId);
            $this->assertSame('2026-09-23 08:00:00', $op->planned_start->toDateTimeString());
        });

        $this->delete("/pp/schedule-ops/{$opId}")->assertRedirect('/pp/schedule-ops');
        $tenant->run(function () use ($opId) {
            $this->assertNull(ScheduleOp::query()->find($opId));
        });
    }

    public function test_committing_into_an_overlapping_window_on_the_same_resource_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $orderAId = $this->seedProductionOrder($tenant, 'PLN-SCH-000003');
        $orderBId = $this->seedProductionOrder($tenant, 'PLN-SCH-000004');

        $opAId = null;
        $opBId = null;
        $tenant->run(function () use (&$opAId, &$opBId, $orderAId, $orderBId) {
            $opAId = ScheduleOp::query()->create([
                'planned_order_id' => $orderAId, 'seq' => 1,
                'resource_type' => 'mes_machine', 'resource_ref_id' => 55,
                'planned_start' => '2026-09-22 08:00:00', 'planned_end' => '2026-09-22 17:00:00',
                'status' => ScheduleOp::STATUS_COMMITTED,
            ])->id;

            // Overlapping window on the SAME resource, still a draft — drafting never conflicts.
            $opBId = ScheduleOp::query()->create([
                'planned_order_id' => $orderBId, 'seq' => 1,
                'resource_type' => 'mes_machine', 'resource_ref_id' => 55,
                'planned_start' => '2026-09-22 12:00:00', 'planned_end' => '2026-09-22 20:00:00',
                'status' => ScheduleOp::STATUS_DRAFT,
            ])->id;
        });

        // Committing the overlapping draft is rejected because op A already holds that window.
        $this->patch("/pp/schedule-ops/{$opBId}/commit")->assertSessionHasErrors('planned_start');

        $tenant->run(function () use ($opBId) {
            $this->assertSame(ScheduleOp::STATUS_DRAFT, ScheduleOp::query()->find($opBId)->status);
        });

        // A non-overlapping resource/window commits cleanly.
        $tenant->run(function () use ($opBId) {
            ScheduleOp::query()->find($opBId)->update(['resource_ref_id' => 56]);
        });
        $this->patch("/pp/schedule-ops/{$opBId}/commit")->assertRedirect('/pp/schedule-ops');
        $tenant->run(function () use ($opBId) {
            $this->assertSame(ScheduleOp::STATUS_COMMITTED, ScheduleOp::query()->find($opBId)->status);
        });
    }

    public function test_split_and_merge_operations(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $orderId = $this->seedProductionOrder($tenant, 'PLN-SCH-000005');

        $opId = null;
        $tenant->run(function () use (&$opId, $orderId) {
            $opId = ScheduleOp::query()->create([
                'planned_order_id' => $orderId, 'seq' => 1,
                'resource_type' => 'mes_work_center', 'resource_ref_id' => 301,
                'planned_start' => '2026-09-22 08:00:00', 'planned_end' => '2026-09-22 20:00:00',
                'status' => ScheduleOp::STATUS_DRAFT,
            ])->id;
        });

        $this->post("/pp/schedule-ops/{$opId}/split", ['split_at' => '2026-09-22 12:00:00'])
            ->assertRedirect('/pp/schedule-ops');

        $secondId = null;
        $tenant->run(function () use (&$secondId, $opId, $orderId) {
            $this->assertSame(2, ScheduleOp::query()->where('planned_order_id', $orderId)->count());

            $first = ScheduleOp::query()->find($opId);
            $this->assertSame('2026-09-22 12:00:00', $first->planned_end->toDateTimeString());

            $second = ScheduleOp::query()->where('planned_order_id', $orderId)->where('id', '!=', $opId)->first();
            $this->assertSame('2026-09-22 12:00:00', $second->planned_start->toDateTimeString());
            $this->assertSame('2026-09-22 20:00:00', $second->planned_end->toDateTimeString());
            $secondId = $second->id;
        });

        // Merge the two halves back together.
        $this->post("/pp/schedule-ops/{$opId}/merge", ['target_id' => $secondId])
            ->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($opId, $secondId, $orderId) {
            $this->assertSame(1, ScheduleOp::query()->where('planned_order_id', $orderId)->count());
            $this->assertNull(ScheduleOp::query()->find($secondId));
            $merged = ScheduleOp::query()->find($opId);
            $this->assertSame('2026-09-22 08:00:00', $merged->planned_start->toDateTimeString());
            $this->assertSame('2026-09-22 20:00:00', $merged->planned_end->toDateTimeString());
        });
    }

    public function test_release_delegates_to_planned_order_and_is_blocked_without_mes(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $orderId = $this->seedProductionOrder($tenant, 'PLN-SCH-000006');

        $opId = null;
        $tenant->run(function () use (&$opId, $orderId) {
            $opId = ScheduleOp::query()->create([
                'planned_order_id' => $orderId, 'seq' => 1,
                'resource_type' => 'mes_work_center', 'resource_ref_id' => 401,
                'planned_start' => '2026-09-22 08:00:00', 'planned_end' => '2026-09-22 17:00:00',
                'status' => ScheduleOp::STATUS_COMMITTED,
            ])->id;
        });

        $this->patch("/pp/schedule-ops/{$opId}/release")->assertSessionHasErrors('order');

        $tenant->run(function () use ($opId, $orderId) {
            $this->assertSame(ScheduleOp::STATUS_COMMITTED, ScheduleOp::query()->find($opId)->status);
            $this->assertSame(PlannedOrder::STATUS_PLANNED, PlannedOrder::query()->find($orderId)->status);
        });
    }
}
