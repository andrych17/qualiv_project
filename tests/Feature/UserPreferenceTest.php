<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Services\LocaleService;
use App\Modules\SysConfig\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_user_can_update_locale_via_http(): void
    {
        $tenant = $this->provisionTenant('pref1');

        $tenant->run(function () {
            $user = User::factory()->create([
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'email_verified_at' => now(),
                'locale' => 'id',
            ]);

            $response = $this->actingAs($user)->post(route('user.locale.update'), [
                'locale' => 'en',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('success');

            $user->refresh();
            $this->assertSame('en', $user->locale);

            $localeService = app(LocaleService::class);
            $this->assertSame('en', $localeService->resolveLocale());
        });
    }

    public function test_any_user_can_update_theme_without_admin_permission(): void
    {
        $tenant = $this->provisionTenant('pref2');

        $tenant->run(function () {
            // Regular user without admin groups or permissions
            $user = User::factory()->create([
                'name' => 'Regular Staff',
                'email' => 'regular@example.com',
                'email_verified_at' => now(),
                'theme' => 'classic-navy',
            ]);

            $response = $this->actingAs($user)->post(route('user.theme.update'), [
                'theme' => 'midnight-dark',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('success');

            $user->refresh();
            $this->assertSame('midnight-dark', $user->theme);

            $themeService = app(ThemeService::class);
            $this->assertSame('midnight-dark', $themeService->getCurrentTheme($user->id));
        });
    }

    public function test_user_can_update_both_preferences_simultaneously(): void
    {
        $tenant = $this->provisionTenant('pref3');

        $tenant->run(function () {
            $user = User::factory()->create([
                'name' => 'Multi Pref User',
                'email' => 'multipref@example.com',
                'email_verified_at' => now(),
                'locale' => 'id',
                'theme' => 'classic-navy',
            ]);

            $response = $this->actingAs($user)->post(route('user.preferences.update'), [
                'locale' => 'en',
                'theme' => 'emerald-horizon',
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('success');

            $user->refresh();
            $this->assertSame('en', $user->locale);
            $this->assertSame('emerald-horizon', $user->theme);
        });
    }

    public function test_invalid_locale_or_theme_fails_validation(): void
    {
        $tenant = $this->provisionTenant('pref4');

        $tenant->run(function () {
            $user = User::factory()->create([
                'name' => 'Invalid Test User',
                'email' => 'invalid@example.com',
                'email_verified_at' => now(),
            ]);

            $responseLocale = $this->actingAs($user)->post(route('user.locale.update'), [
                'locale' => 'invalid-locale',
            ]);
            $responseLocale->assertSessionHasErrors('locale');

            $responseTheme = $this->actingAs($user)->post(route('user.theme.update'), [
                'theme' => 'invalid-theme-key',
            ]);
            $responseTheme->assertSessionHasErrors('theme');
        });
    }

    public function test_locale_service_loads_translations_dictionary(): void
    {
        $tenant = $this->provisionTenant('pref5');

        $tenant->run(function () {
            $service = app(LocaleService::class);
            LocaleService::clearCache();

            $idTranslations = $service->getTranslations('id');
            // Shared common keys
            $this->assertArrayHasKey('common.save', $idTranslations);
            $this->assertSame('Simpan', $idTranslations['common.save']);
            // Shared error keys
            $this->assertArrayHasKey('error.unauthorized', $idTranslations);
            $this->assertArrayHasKey('messages.saved_success', $idTranslations);
            // Isolated module keys (Legal, Accounting, CRM, Inventory, Sales)
            $this->assertArrayHasKey('legal.matter', $idTranslations);
            $this->assertSame('Perkara Hukum', $idTranslations['legal.matter']);
            $this->assertArrayHasKey('accounting.account', $idTranslations);
            $this->assertSame('Akun Perkiraan', $idTranslations['accounting.account']);
            $this->assertArrayHasKey('crm.lead', $idTranslations);
            $this->assertArrayHasKey('inventory.product', $idTranslations);
            $this->assertArrayHasKey('sales.quotation', $idTranslations);

            $enTranslations = $service->getTranslations('en');
            // Shared common keys
            $this->assertArrayHasKey('common.save', $enTranslations);
            $this->assertSame('Save', $enTranslations['common.save']);
            // Shared error keys
            $this->assertArrayHasKey('error.unauthorized', $enTranslations);
            $this->assertArrayHasKey('messages.saved_success', $enTranslations);
            // Isolated module keys
            $this->assertArrayHasKey('legal.matter', $enTranslations);
            $this->assertSame('Legal Matter', $enTranslations['legal.matter']);
            $this->assertArrayHasKey('accounting.account', $enTranslations);
            $this->assertSame('Account', $enTranslations['accounting.account']);
            $this->assertArrayHasKey('crm.lead', $enTranslations);
            $this->assertArrayHasKey('inventory.product', $enTranslations);
            $this->assertArrayHasKey('sales.quotation', $enTranslations);

            // Verify backend Laravel helper __('...') resolution
            app()->setLocale('id');
            $this->assertSame('Perkara Hukum', __('legal.matter'));
            $this->assertSame('Simpan', __('common.save'));

            app()->setLocale('en');
            $this->assertSame('Legal Matter', __('legal.matter'));
            $this->assertSame('Save', __('common.save'));
        });
    }
}
