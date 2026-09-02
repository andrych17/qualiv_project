<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\ScheduleOp;
use App\Modules\PP\Services\SchedulingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3I — dispatch strategies over one resource's draft queue: Earliest Due Date
 * reorders by need_by_date, applying only rewrites seq (never the window), a committed op is
 * left untouched by the resequence, and the §3J-dependent strategies are rejected until that
 * module ships.
 */
class PPSchedulingRuleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function seedOrderAndOp($tenant, string $plan, string $needByDate, string $start, string $end, string $status = ScheduleOp::STATUS_DRAFT): int
    {
        $opId = null;
        $tenant->run(function () use (&$opId, $plan, $needByDate, $start, $end, $status) {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => "SR-{$plan}", 'name' => 'Rule Test Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            $order = PlannedOrder::query()->create([
                'plan_number' => $plan,
                'order_type' => PlannedOrder::TYPE_PRODUCTION,
                'product_id' => $product->id,
                'qty' => 10,
                'need_by_date' => $needByDate,
                'bom_id' => $bom->id,
                'status' => PlannedOrder::STATUS_PLANNED,
            ]);

            $opId = ScheduleOp::query()->create([
                'planned_order_id' => $order->id, 'seq' => 1,
                'resource_type' => 'mes_work_center', 'resource_ref_id' => 501,
                'planned_start' => $start, 'planned_end' => $end,
                'status' => $status,
            ])->id;
        });

        return $opId;
    }

    public function test_earliest_due_date_reorders_seq_only_and_skips_committed_ops(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        // Later due date, scheduled first (seq 1) — EDD should push it after the earlier-due one.
        $lateOpId = $this->seedOrderAndOp($tenant, 'PLN-SR-000001', '2026-10-20', '2026-09-22 08:00:00', '2026-09-22 12:00:00');
        $earlyOpId = $this->seedOrderAndOp($tenant, 'PLN-SR-000002', '2026-09-25', '2026-09-23 08:00:00', '2026-09-23 12:00:00');

        // A committed op on the SAME resource must be left untouched by the resequence.
        $committedOpId = $this->seedOrderAndOp($tenant, 'PLN-SR-000003', '2026-09-01', '2026-09-24 08:00:00', '2026-09-24 12:00:00', ScheduleOp::STATUS_COMMITTED);

        $tenant->run(function () use ($lateOpId, $earlyOpId) {
            $this->assertSame(1, ScheduleOp::query()->find($lateOpId)->seq);
            $this->assertSame(1, ScheduleOp::query()->find($earlyOpId)->seq);
        });

        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center',
            'resource_ref_id' => 501,
            'strategy' => SchedulingRuleService::STRATEGY_EARLIEST_DUE_DATE,
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($lateOpId, $earlyOpId, $committedOpId) {
            $early = ScheduleOp::query()->find($earlyOpId);
            $late = ScheduleOp::query()->find($lateOpId);
            $this->assertSame(1, $early->seq);
            $this->assertSame(2, $late->seq);

            // Windows are untouched — only seq moved.
            $this->assertSame('2026-09-22 08:00:00', $late->planned_start->toDateTimeString());
            $this->assertSame('2026-09-23 08:00:00', $early->planned_start->toDateTimeString());

            // The committed op is completely unaffected.
            $committed = ScheduleOp::query()->find($committedOpId);
            $this->assertSame(1, $committed->seq);
            $this->assertSame(ScheduleOp::STATUS_COMMITTED, $committed->status);
        });
    }

    public function test_priority_strategy_dispatches_sales_order_linked_demand_first(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $manualOpId = $this->seedOrderAndOp($tenant, 'PLN-SR-000004', '2026-09-10', '2026-09-22 08:00:00', '2026-09-22 12:00:00');

        $soOpId = null;
        $tenant->run(function () use (&$soOpId) {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'SR-SO-LINKED', 'name' => 'SO-linked Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);

            $header = DemandHeader::query()->create([
                'source_type' => DemandHeader::SOURCE_SALES_ORDER,
                'subject_type' => 'App\\Modules\\Sales\\Models\\SalesOrder',
                'subject_id' => 999,
                'demand_date' => now()->toDateString(),
            ]);
            $line = DemandLine::query()->create([
                'demand_hdr_id' => $header->id, 'product_id' => $product->id,
                'need_by_date' => now()->addDays(30)->toDateString(), 'qty' => 5,
            ]);

            $order = PlannedOrder::query()->create([
                'plan_number' => 'PLN-SR-000005',
                'order_type' => PlannedOrder::TYPE_PRODUCTION,
                'product_id' => $product->id,
                'qty' => 5,
                'need_by_date' => now()->addDays(30)->toDateString(),
                'bom_id' => $bom->id,
                'source_type' => 'demand_line',
                'source_id' => $line->id,
                'status' => PlannedOrder::STATUS_PLANNED,
            ]);

            $soOpId = ScheduleOp::query()->create([
                'planned_order_id' => $order->id, 'seq' => 2,
                'resource_type' => 'mes_work_center', 'resource_ref_id' => 502,
                'planned_start' => '2026-09-25 08:00:00', 'planned_end' => '2026-09-25 12:00:00',
                'status' => ScheduleOp::STATUS_DRAFT,
            ])->id;
        });

        // Put the manual (non-SO) op on the same resource, seq before the SO-linked one.
        $tenant->run(function () use ($manualOpId) {
            ScheduleOp::query()->find($manualOpId)->update(['resource_ref_id' => 502, 'seq' => 1]);
        });

        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center',
            'resource_ref_id' => 502,
            'strategy' => SchedulingRuleService::STRATEGY_PRIORITY,
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($soOpId, $manualOpId) {
            $this->assertSame(1, ScheduleOp::query()->find($soOpId)->seq);
            $this->assertSame(2, ScheduleOp::query()->find($manualOpId)->seq);
        });
    }

    /**
     * §3J shipped the changeover matrix, so minimize_setup/minimize_changeover moved from
     * PENDING to AVAILABLE (see SchedulingRuleService::AVAILABLE) — this used to assert
     * rejection; now it asserts the strategy is at least selectable against an empty queue
     * without error (a real regression the greedy construction hit: array_shift() on an empty
     * remaining list produced a null element, crashing ScheduleOpService::applyStrategy()'s
     * `$op->update()` loop — see SchedulingRuleService::applyChangeoverGreedy()'s early-return).
     */
    public function test_minimize_setup_is_selectable_against_an_empty_queue(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center',
            'resource_ref_id' => 999,
            'strategy' => SchedulingRuleService::STRATEGY_MINIMIZE_SETUP,
        ])->assertRedirect('/pp/schedule-ops')->assertSessionDoesntHaveErrors();
    }

    public function test_unknown_strategy_throws_from_the_service_directly(): void
    {
        $this->expectException(ValidationException::class);

        app(SchedulingRuleService::class)->apply('not_a_real_strategy', collect());
    }
}
