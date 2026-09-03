<?php

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\POS\Models\PosBranch;
use App\Modules\POS\Models\PosProfile;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTerminal;
use App\Modules\POS\Services\PosSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * POS_SPECS.md §3C, §3D — POS Session and Cash Management Tests.
 */
class PosSessionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_can_open_and_close_session_with_variance_calculation(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Main POS Warehouse', 'address' => 'Store 01']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $branch = PosBranch::query()->create(['code' => 'BR-TEST', 'name' => 'Test Branch']);

            $terminal = PosTerminal::query()->create([
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'TERM-01',
                'name' => 'Counter 1',
                'receipt_prefix' => 'T01',
            ]);

            $sessionService = app(PosSessionService::class);

            // 1. Open session with IDR 500,000 float
            $session = $sessionService->openSession($terminal->id, $user->id, 500000.0);
            $this->assertEquals(PosSession::STATUS_OPEN, $session->status);
            $this->assertEquals(500000.0, (float) $session->opening_cash);

            // 2. Reject opening a second open session on the same terminal
            $this->expectException(ValidationException::class);
            $sessionService->openSession($terminal->id, $user->id, 200000.0);
        });
    }

    public function test_cash_movements_and_close_session_with_variance_threshold(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Main POS Warehouse 2', 'address' => 'Store 02']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'TERM-02',
                'name' => 'Counter 2',
                'receipt_prefix' => 'T02',
            ]);

            $sessionService = app(PosSessionService::class);
            $session = $sessionService->openSession($terminal->id, $user->id, 300000.0);

            // Cash movements
            $sessionService->addCashMovement($session->id, 'cash_in', 50000.0, 'Add float coins', $user->id);
            $sessionService->addCashMovement($session->id, 'petty_cash', 20000.0, 'Buy cleaning supplies', $user->id);

            // Expected cash = 300,000 + 50,000 - 20,000 = 330,000
            // Actual cash = 330,000 -> variance = 0 (no supervisor PIN required)
            $closed = $sessionService->closeSession($session->id, 330000.0, $user->id);
            $this->assertEquals(PosSession::STATUS_CLOSED, $closed->status);
            $this->assertEquals(330000.0, (float) $closed->expected_cash);
            $this->assertEquals(330000.0, (float) $closed->actual_cash);
            $this->assertEquals(0.0, (float) $closed->variance);
            $this->assertNotNull($closed->closed_at);
        });
    }

    public function test_large_variance_requires_supervisor_pin(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $user = User::query()->where('email', 'admin@nusaevo.com')->first();
            $warehouse = Warehouse::query()->create(['name' => 'Main POS Warehouse 3', 'address' => 'Store 03']);
            $profile = PosProfile::query()->where('code', 'CONVENIENCE')->first();
            $terminal = PosTerminal::query()->create([
                'warehouse_id' => $warehouse->id,
                'profile_id' => $profile->id,
                'code' => 'TERM-03',
                'name' => 'Counter 3',
                'receipt_prefix' => 'T03',
            ]);

            $sessionService = app(PosSessionService::class);
            $session = $sessionService->openSession($terminal->id, $user->id, 500000.0);

            // Variance: expected = 500,000, actual = 400,000 => variance = -100,000 (> threshold of 50,000)
            // Without PIN should fail
            try {
                $sessionService->closeSession($session->id, 400000.0, $user->id);
                $this->fail('Expected ValidationException when closing session with large variance without PIN');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('variance', $e->errors());
            }

            // With supervisor PIN '1234' (default) should pass
            $closed = $sessionService->closeSession($session->id, 400000.0, $user->id, '1234');
            $this->assertEquals(PosSession::STATUS_CLOSED, $closed->status);
            $this->assertEquals(-100000.0, (float) $closed->variance);
            $this->assertNotNull($closed->approved_by);
        });
    }
}
