<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Models\PpException;
use App\Modules\PP\Models\ResourceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3M — Planning Exception Center: exceptions are written by the owning engine
 * (§3F capacity overload, §3D late orders), never entered by hand, and the status workflow
 * (open → acknowledged/resolved) works over HTTP.
 */
class PPExceptionCenterTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_capacity_overload_writes_an_exception_and_rerun_does_not_duplicate_it(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $groupId = null;
        $tenant->run(function () use (&$groupId) {
            $group = ResourceGroup::query()->create(['code' => 'ASM', 'name' => 'Assembly Line A', 'is_active' => true]);
            $groupId = $group->id;
        });

        // 620hr required vs 500hr available => 124% load, above the default 100% threshold.
        $this->post('/pp/capacity-plans', [
            'resource_group_id' => $groupId,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'required_hours' => 620,
            'available_hours' => 500,
        ])->assertRedirect('/pp/capacity-plans');

        $tenant->run(function () {
            $this->assertSame(1, PpException::query()->where('exception_type', PpException::TYPE_CAPACITY_OVERLOAD)->count());
            $exception = PpException::query()->where('exception_type', PpException::TYPE_CAPACITY_OVERLOAD)->first();
            $this->assertSame(PpException::STATUS_OPEN, $exception->status);
            $this->assertSame(PpException::SEVERITY_HIGH, $exception->severity); // 124% >= 120
        });

        $planId = null;
        $tenant->run(function () use (&$planId) {
            $planId = CapacityPlan::query()->first()->id;
        });

        // Saving the same overloaded values again must not create a second open exception.
        $this->put("/pp/capacity-plans/{$planId}", [
            'resource_group_id' => $groupId,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'required_hours' => 620,
            'available_hours' => 500,
        ])->assertRedirect('/pp/capacity-plans');

        $tenant->run(function () {
            $this->assertSame(1, PpException::query()->where('exception_type', PpException::TYPE_CAPACITY_OVERLOAD)->count());
        });
    }

    public function test_mrp_run_flags_a_past_due_planned_order_as_a_late_order_exception(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'EXC-FG-01', 'name' => 'Exception Test Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productId = $product->id;

            // A BOM makes this a PRODUCTION-type order (PP_SPECS.md §3L splits late orders by
            // order_type — a BOM-less product would come out PURCHASE-type and raise
            // TYPE_LATE_PURCHASE instead of the TYPE_LATE_ORDER this test targets).
            Bom::query()->create(['product_id' => $productId, 'version' => 1, 'is_active' => true]);

            // Long lead time pushes the computed need_by_date into the past even though the
            // demand line's own need-by is still in the future.
            ItemPlanningParam::query()->create(['product_id' => $productId, 'lead_time_days' => 30]);
        });

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'need_by_date' => now()->addDays(5)->toDateString(), 'qty' => 10]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $exceptionId = null;
        $tenant->run(function () use (&$exceptionId) {
            $exception = PpException::query()->where('exception_type', PpException::TYPE_LATE_ORDER)->first();
            $this->assertNotNull($exception);
            $this->assertSame(PpException::STATUS_OPEN, $exception->status);
            $exceptionId = $exception->id;
        });

        // Acknowledge then resolve over HTTP.
        $this->patch("/pp/exceptions/{$exceptionId}/acknowledge")->assertRedirect();
        $tenant->run(function () use ($exceptionId) {
            $this->assertSame(PpException::STATUS_ACKNOWLEDGED, PpException::query()->find($exceptionId)->status);
        });

        $this->patch("/pp/exceptions/{$exceptionId}/resolve")->assertRedirect();
        $tenant->run(function () use ($exceptionId) {
            $exception = PpException::query()->find($exceptionId);
            $this->assertSame(PpException::STATUS_RESOLVED, $exception->status);
            $this->assertNotNull($exception->resolved_by);
            $this->assertNotNull($exception->resolved_at);
        });

        // Index renders with the resolved row filtered out of the default "open" view.
        $this->get('/pp/exceptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/Exceptions/Index')
                ->where('exceptions.data', [])
                ->where('exceptions.total', 0)
            );
    }

    /**
     * PP_SPECS.md §3M's drill-down ("Problem → Affected Order → Affected Material/Resource →
     * Suggested Actions") and the type-count summary bar — the populated-list path the previous
     * test's empty-state assertion never exercised. Also proves the `exception_type` filter
     * (what the count chips drive) actually narrows the paginated list.
     */
    public function test_index_returns_counts_by_type_a_paginated_list_and_suggested_actions(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $groupId = null;
        $tenant->run(function () use (&$groupId) {
            $groupId = ResourceGroup::query()->create(['code' => 'IDX', 'name' => 'Index Line', 'is_active' => true])->id;
        });

        $this->post('/pp/capacity-plans', [
            'resource_group_id' => $groupId,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
            'required_hours' => 620,
            'available_hours' => 500,
        ])->assertRedirect('/pp/capacity-plans');

        $this->get('/pp/exceptions?status=open')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/Exceptions/Index')
                ->where('counts.capacity_overload', 1)
                ->where('exceptions.total', 1)
                ->where('exceptions.data.0.exception_type', PpException::TYPE_CAPACITY_OVERLOAD)
                ->where('exceptions.data.0.suggested_actions', [
                    'Add overtime', 'Add a shift', 'Move production to another period',
                    'Use an alternate resource', 'Outsource the excess load', 'Change quantity or due date',
                ])
            );

        // The type filter (what a count-chip click sends) narrows the list to zero for a type
        // that has no open rows, without erroring.
        $this->get('/pp/exceptions?status=open&exception_type='.PpException::TYPE_LATE_ORDER)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/Exceptions/Index')
                ->where('exceptions.total', 0)
                ->where('currentType', PpException::TYPE_LATE_ORDER)
            );
    }
}
