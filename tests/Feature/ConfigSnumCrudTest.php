<?php

namespace Tests\Feature;

use App\Modules\SysConfig\Models\ConfigSnum;
use App\Modules\SysConfig\Services\ConfigSnumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ConfigSnumCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_crud_serial_and_next_increments(): void
    {
        $tenant = $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/config/serials')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Config/Serials/Index'));

        $this->post('/config/serials', [
            'code' => 'LEGAL_CASE_LASTID',
            'last_cnt' => 10,
            'wrap_low' => 1,
            'wrap_high' => 999999,
            'step_cnt' => 1,
            'descr' => 'Legal cases',
            'status_code' => 'A',
        ])->assertRedirect(route('config.serials.index'));

        $id = null;
        $tenant->run(function () use (&$id) {
            $row = ConfigSnum::query()->where('code', 'LEGAL_CASE_LASTID')->first();
            $this->assertNotNull($row);
            $this->assertSame(10, $row->last_cnt);
            $id = $row->id;

            $n = app(ConfigSnumService::class)->next('LEGAL_CASE_LASTID');
            $this->assertSame(11, $n);
            $this->assertSame(11, ConfigSnum::query()->where('code', 'LEGAL_CASE_LASTID')->value('last_cnt'));
        });

        $this->put('/config/serials/'.$id, [
            'code' => 'LEGAL_CASE_LASTID',
            'last_cnt' => 20,
            'wrap_low' => 1,
            'wrap_high' => 999999,
            'step_cnt' => 1,
            'descr' => 'Legal cases updated',
            'status_code' => 'A',
        ])->assertRedirect(route('config.serials.index'));
    }

    public function test_next_wraps_at_high(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            ConfigSnum::query()->create([
                'code' => 'WRAP_TEST',
                'last_cnt' => 5,
                'wrap_low' => 1,
                'wrap_high' => 5,
                'step_cnt' => 1,
                'status_code' => 'A',
            ]);

            $n = app(ConfigSnumService::class)->next('WRAP_TEST');
            $this->assertSame(1, $n);
        });
    }
}
