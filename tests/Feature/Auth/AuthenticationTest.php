<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $this->provisionTenant('001', 'user@example.com', 'password');

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $this->provisionTenant('001', 'user@example.com', 'password');

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $this->provisionTenant('001', 'user@example.com', 'password');

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
        $this->assertFalse(tenancy()->initialized);
    }
}
