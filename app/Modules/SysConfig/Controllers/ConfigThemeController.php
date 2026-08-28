<?php

namespace App\Modules\SysConfig\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SysConfig\Services\ThemeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigThemeController extends Controller
{
    public function __construct(
        protected ThemeService $themeService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Config/Theme/Index', [
            'themes' => $this->themeService->getAvailableThemes(),
            'currentTheme' => $this->themeService->getCurrentTheme(),
            'defaultTheme' => ThemeService::DEFAULT_THEME,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', 'in:'.implode(',', array_keys(ThemeService::THEMES))],
        ]);

        $this->themeService->setTenantTheme($data['theme']);

        return back()->with('success', 'Tema tenant berhasil diperbarui dan diterapkan ke seluruh sistem!');
    }
}
