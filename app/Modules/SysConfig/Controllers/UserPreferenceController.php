<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Services\LocaleService;
use App\Modules\SysConfig\Services\ThemeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function __construct(
        protected LocaleService $localeService,
        protected ThemeService $themeService,
    ) {}

    /**
     * Update user language / locale preference.
     */
    public function updateLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys(LocaleService::SUPPORTED_LOCALES))],
        ]);

        $locale = $validated['locale'];

        if ($user = $request->user()) {
            $this->localeService->setUserLocale($user, $locale);
        } else {
            $request->session()->put('locale', $locale);
            app()->setLocale($locale);
        }

        return back()->with('success', $locale === 'en' ? 'Language preference updated.' : 'Preferensi bahasa berhasil diperbarui.');
    }

    /**
     * Update user theme preference (accessible to all authenticated users).
     */
    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', array_keys(ThemeService::THEMES))],
        ]);

        $theme = $validated['theme'];

        if ($user = $request->user()) {
            $this->themeService->setUserTheme($user, $theme);
        } else {
            $request->session()->put('theme', $theme);
        }

        return back()->with('success', 'Tema tampilan berhasil diperbarui.');
    }

    /**
     * Update both locale and theme simultaneously.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'in:'.implode(',', array_keys(LocaleService::SUPPORTED_LOCALES))],
            'theme' => ['nullable', 'string', 'in:'.implode(',', array_keys(ThemeService::THEMES))],
        ]);

        $user = $request->user();

        if (! empty($validated['locale'])) {
            if ($user) {
                $this->localeService->setUserLocale($user, $validated['locale']);
            } else {
                $request->session()->put('locale', $validated['locale']);
                app()->setLocale($validated['locale']);
            }
        }

        if (! empty($validated['theme'])) {
            if ($user) {
                $this->themeService->setUserTheme($user, $validated['theme']);
            } else {
                $request->session()->put('theme', $validated['theme']);
            }
        }

        return back()->with('success', 'Pengaturan preferensi berhasil disimpan.');
    }
}
