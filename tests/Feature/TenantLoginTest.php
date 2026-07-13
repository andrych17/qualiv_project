<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class TenantLoginTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_seeded_admin_can_login_and_initializes_tenant(): void
    {
        $this->provisionTenant();

        $response = $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
        $this->assertSame('001', tenant('id'));
        $this->assertSame('001', session('tenant_id'));
    }

    public function test_dashboard_works_on_subsequent_request(): void
    {
        $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        // New request — tenancy must re-init from session before Auth loads user.
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));

        $this->assertSame('001', tenant('id'));
        $this->assertAuthenticated();
    }
}
