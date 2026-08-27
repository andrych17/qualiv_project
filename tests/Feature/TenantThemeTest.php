<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class TenantThemeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_default_theme_is_classic_navy(): void
    {
        $tenant = $this->provisionTenant('th1');

        $tenant->run(function () {
            $service = app(ThemeService::class);
            $this->assertSame('classic-navy', $service->getCurrentTheme());
            $this->assertGreaterThanOrEqual(5, count($service->getAvailableThemes()));
        });
    }

    public function test_tenant_theme_can_be_updated_to_light_and_dark_in_sysconfig(): void
    {
        $tenant = $this->provisionTenant('th2');

        $tenant->run(function () {
            $service = app(ThemeService::class);

            // Light theme
            $service->setTenantTheme('emerald-horizon');
            $this->assertSame('emerald-horizon', $service->getCurrentTheme());

            // Dark theme
            $service->setTenantTheme('midnight-dark');
            $this->assertSame('midnight-dark', $service->getCurrentTheme());

            // Another dark theme
            $service->setTenantTheme('forest-dark');
            $this->assertSame('forest-dark', $service->getCurrentTheme());
        });
    }

    public function test_invalid_theme_throws_exception(): void
    {
        $tenant = $this->provisionTenant('th3');

        $tenant->run(function () {
            $service = app(ThemeService::class);

            $this->expectException(\InvalidArgumentException::class);
            $service->setTenantTheme('non-existent-theme');
        });
    }

    public function test_admin_can_access_theme_page_and_update_theme_via_http(): void
    {
        $tenant = $this->provisionTenant('th4');

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();

            $response = $this->actingAs($admin)->get(route('config.theme.index'));
            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->component('Config/Theme/Index')
                ->where('currentTheme', 'classic-navy')
            );

            // Test updating to dark mode theme
            $updateResponse = $this->actingAs($admin)->post(route('config.theme.update'), [
                'theme' => 'midnight-dark',
            ]);

            $updateResponse->assertRedirect();
            $updateResponse->assertSessionHas('success');

            $service = app(ThemeService::class);
            $this->assertSame('midnight-dark', $service->getCurrentTheme());
        });
    }
}
