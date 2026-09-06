<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * DashboardService builds each KPI card's `delta` from a created_at range query. Those columns
 * are not uniform across modules (INVENTORY.items has none), so a missing column would only
 * surface as a 500 on the tenant's first screen after login. This hits the real route against a
 * real tenant DB so that regression fails here instead.
 */
class DashboardPayloadTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_dashboard_renders_cards_with_deltas_and_no_launcher_payload(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => '001',
        ])->assertRedirect('/dashboard');

        $response = $this->get('/dashboard');
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertNotEmpty($props['cards'], 'the full plan should surface at least one KPI card');
        $this->assertArrayNotHasKey('appSections', $props, 'the module launcher payload was removed');

        foreach ($props['cards'] as $card) {
            $this->assertArrayHasKey('value', $card);

            // delta is optional by design, but when present it must be a real count.
            if (array_key_exists('delta', $card)) {
                $this->assertIsInt($card['delta']);
                $this->assertGreaterThanOrEqual(0, $card['delta']);
            }
        }

        $withDelta = array_filter($props['cards'], fn ($c) => array_key_exists('delta', $c));
        $this->assertNotEmpty($withDelta, 'at least one card should carry a 30-day delta');
    }
}
