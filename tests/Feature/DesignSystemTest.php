<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

// ponytail: Feature test for Design System component showcase page
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_authenticated_user_can_access_design_system_page(): void
    {
        $this->provisionTenant();

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/design-system')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('DesignSystem/Index')
                ->has('navMenus'));
    }
}
