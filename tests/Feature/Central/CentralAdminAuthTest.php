<?php

namespace Tests\Feature\Central;

use App\Modules\Central\Models\CentralAdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_log_in_and_out(): void
    {
        $admin = CentralAdminUser::query()->create([
            'name' => 'Simon',
            'email' => 'simon@nusaevo.com',
            'password' => 'password',
        ]);

        $response = $this->post('/central/login', [
            'email' => 'simon@nusaevo.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('central.dashboard'));
        $this->assertAuthenticatedAs($admin, 'central_admin');

        $this->post('/central/logout')->assertRedirect(route('central.login'));
        $this->assertGuest('central_admin');
    }

    public function test_wrong_password_is_rejected(): void
    {
        CentralAdminUser::query()->create([
            'name' => 'Simon',
            'email' => 'simon@nusaevo.com',
            'password' => 'password',
        ]);

        $response = $this->post('/central/login', [
            'email' => 'simon@nusaevo.com',
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('central_admin');
    }
}
