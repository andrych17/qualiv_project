<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MES\Models\AndonAlert;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\AndonService;
use App\Modules\PP\Models\Bom;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3R — Alerts & Andon (Phase 3, built now per explicit override). The board is a
 * pure read model over `mes_machines.status`; `checkAndFireAlerts()` is the only thing that
 * writes (`mes_andon_alerts`), and only for once-only delivery bookkeeping.
 */
class MesAndonAlertTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_board_derives_andon_state_from_machine_status(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $wc = WorkCenter::query()->create(['code' => 'WC-AND', 'name' => 'Andon Line', 'type' => 'discrete']);
            Machine::query()->create(['work_center_id' => $wc->id, 'code' => 'M-RUN', 'name' => 'Runner', 'status' => Machine::STATUS_RUNNING]);
            Machine::query()->create(['work_center_id' => $wc->id, 'code' => 'M-DOWN', 'name' => 'Downer', 'status' => Machine::STATUS_DOWN]);
            Machine::query()->create(['work_center_id' => $wc->id, 'code' => 'M-MAINT', 'name' => 'Maintainer', 'status' => Machine::STATUS_MAINTENANCE]);
            Machine::query()->create(['work_center_id' => $wc->id, 'code' => 'M-WAIT', 'name' => 'Waiter', 'status' => Machine::STATUS_WAITING_MATERIAL]);
        });

        $this->get('/mes/andon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Andon/Index')
                ->where('board', function ($board) {
                    $byCode = collect($board)->keyBy('code');

                    return $byCode['M-RUN']['andon_state'] === 'running'
                        && $byCode['M-DOWN']['andon_state'] === 'stopped'
                        && $byCode['M-MAINT']['andon_state'] === 'maintenance'
                        && $byCode['M-WAIT']['andon_state'] === 'attention';
                })
            );
    }

    public function test_machine_stopped_alert_fires_once_and_auto_resolves(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            Event::fake([NotificationRequested::class]);

            $wc = WorkCenter::query()->create(['code' => 'WC-STOP', 'name' => 'Stop Line', 'type' => 'discrete']);
            $machine = Machine::query()->create(['work_center_id' => $wc->id, 'code' => 'M-STOP', 'name' => 'Stopper', 'status' => Machine::STATUS_DOWN]);

            $fired = app(AndonService::class)->checkAndFireAlerts();
            $this->assertSame(1, $fired);

            Event::assertDispatched(NotificationRequested::class, function (NotificationRequested $event) use ($machine) {
                return $event->category === 'mes.andon_machine_stopped'
                    && $event->subjectType === 'mes.mes_machines'
                    && $event->subjectId === $machine->id;
            });

            $this->assertSame(1, AndonAlert::query()->open()->where('alert_type', AndonAlert::TYPE_MACHINE_STOPPED)->where('subject_id', $machine->id)->count());

            // A second sweep with the same condition still true does not re-fire.
            $firedAgain = app(AndonService::class)->checkAndFireAlerts();
            $this->assertSame(0, $firedAgain);

            // Machine recovers — the open alert auto-resolves.
            $machine->update(['status' => Machine::STATUS_IDLE]);
            app(AndonService::class)->checkAndFireAlerts();
            $this->assertSame(0, AndonAlert::query()->open()->where('alert_type', AndonAlert::TYPE_MACHINE_STOPPED)->where('subject_id', $machine->id)->count());
            $this->assertNotNull(AndonAlert::query()->where('alert_type', AndonAlert::TYPE_MACHINE_STOPPED)->where('subject_id', $machine->id)->first()->resolved_at);
        });
    }

    public function test_behind_schedule_alert_fires_for_a_past_due_active_assembly_order(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            Event::fake([NotificationRequested::class]);

            $product = Product::query()->create([
                'sku' => 'AND-BEHIND', 'name' => 'Andon Behind Widget',
                'base_uom_id' => Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces'])->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            $order = ProdOrder::query()->create([
                'order_number' => 'MO-AND-BEHIND', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'status' => ProdOrder::STATUS_IN_PROGRESS, 'planned_end' => now()->subHour(),
            ]);

            $fired = app(AndonService::class)->checkAndFireAlerts();
            $this->assertGreaterThanOrEqual(1, $fired);

            $this->assertSame(1, AndonAlert::query()->open()->where('alert_type', AndonAlert::TYPE_BEHIND_SCHEDULE)->where('subject_id', $order->id)->count());
        });
    }

    public function test_andon_sweep_command_runs_within_tenant_context(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $this->assertSame(0, DowntimeEvent::query()->count());
            $this->artisan('mes:check-andon-alerts')->assertExitCode(0);
        });
    }
}
