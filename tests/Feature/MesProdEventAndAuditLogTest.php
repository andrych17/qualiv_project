<?php

namespace Tests\Feature;

use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Services\MesAuditLogger;
use App\Modules\MES\Services\ProdEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3C Production Event Ledger + §3U Digital Audit Trail — both read-only, system-written only. */
class MesProdEventAndAuditLogTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_prod_event_index_filters_by_order_event_type_and_search(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $machineId = null;
        $tenant->run(function () use (&$orderId, &$machineId) {
            $product = $this->makeProduct('EV-1');
            $recipeId = $this->makeRecipe($product->id)->id;
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-EV1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $orderId = $order->id;
            $machineId = $this->makeMachine($this->makeWorkCenter())->id;

            app(ProdEventService::class)->record($orderId, ProdEvent::TYPE_ORDER_RELEASED, ['qty' => 5], $this->adminUserId(), $machineId);
            app(ProdEventService::class)->record($orderId, ProdEvent::TYPE_MACHINE_STARTED, [], $this->adminUserId(), $machineId);
        });

        $this->get('/mes/prod-events')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProdEvents/Index')->has('events.data', 2));

        $this->get("/mes/prod-events?order_id={$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 2));

        $this->get('/mes/prod-events?event_type='.ProdEvent::TYPE_MACHINE_STARTED)->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 1));

        $this->get('/mes/prod-events?search=WO-EV1&sort=occurred_at&direction=asc')->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 2));

        $this->get('/mes/prod-events?search=NO-MATCH')->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 0));
    }

    public function test_audit_log_index_filters_by_subject_type_action_and_actor(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $actorId = null;
        $tenant->run(function () use (&$actorId) {
            $actorId = $this->adminUserId();
            app(MesAuditLogger::class)->log('mes.mes_process_phases', 1, 'updated', ['a' => 1], ['a' => 2], $actorId);
            app(MesAuditLogger::class)->log('mes.mes_process_phases', 2, 'updated', null, ['a' => 1], $actorId);
        });

        $this->get('/mes/audit-logs')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/AuditLogs/Index')->has('logs.data', 2));

        $this->get('/mes/audit-logs?subject_type=mes.mes_process_phases')->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->get('/mes/audit-logs?action=updated&sort=created_at&direction=asc')->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->get("/mes/audit-logs?actor_id={$actorId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 2));

        $this->get('/mes/audit-logs?subject_type=no.such.subject')->assertOk()
            ->assertInertia(fn ($page) => $page->has('logs.data', 0));
    }
}
