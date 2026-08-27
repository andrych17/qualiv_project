<?php

namespace App\Modules\SysConfig\Services;

class ThemeService
{
    public const DEFAULT_THEME = 'classic-navy';

    public const DEFAULT_DARK_THEME = 'midnight-dark';

    /**
     * Curated Light & Dark Themes for NusaEvo ERP
     *
     * @var array<string, array<string, mixed>>
     */
    public const THEMES = [
        'classic-navy' => [
            'id' => 'classic-navy',
            'name' => 'Classic Navy',
            'mode' => 'light',
            'caption' => 'Enterprise Standard (Light)',
            'description' => 'Tema klasik enterprise dengan aksen royal navy yang profesional, tenang, dan tegas.',
            'primary_color' => '#1f5fbf',
            'preview_colors' => ['#1f5fbf', '#12181f', '#f4f6f8', '#dee3e8'],
            'badge' => 'Default Light',
        ],
        'midnight-dark' => [
            'id' => 'midnight-dark',
            'name' => 'Midnight Dark',
            'mode' => 'dark',
            'caption' => 'Enterprise Obsidian (Dark)',
            'description' => 'Mode gelap enterprise dengan latar slate charcoal dan aksen electric sky blue yang tajam dan nyaman di malam hari.',
            'primary_color' => '#38bdf8',
            'preview_colors' => ['#38bdf8', '#f8fafc', '#0f172a', '#334155'],
            'badge' => 'Default Dark',
        ],
        'emerald-horizon' => [
            'id' => 'emerald-horizon',
            'name' => 'Emerald Horizon',
            'mode' => 'light',
            'caption' => 'Nature & Legal (Light)',
            'description' => 'Nuansa hijau emerald elegan yang segar dan berwibawa, cocok untuk firma hukum dan instansi.',
            'primary_color' => '#0d8a68',
            'preview_colors' => ['#0d8a68', '#0f1f1a', '#f2f8f5', '#d3e4dc'],
            'badge' => 'Legal Light',
        ],
        'forest-dark' => [
            'id' => 'forest-dark',
            'name' => 'Forest Night',
            'mode' => 'dark',
            'caption' => 'Deep Forest (Dark)',
            'description' => 'Mode gelap bertema hutan tropis dengan aksen mint bercahaya yang kontras dan elegan.',
            'primary_color' => '#10b981',
            'preview_colors' => ['#10b981', '#f0fdf4', '#0a1712', '#1e3d31'],
            'badge' => 'Legal Dark',
        ],
        'royal-amethyst' => [
            'id' => 'royal-amethyst',
            'name' => 'Royal Amethyst',
            'mode' => 'light',
            'caption' => 'Executive & Tech (Light)',
            'description' => 'Aksen ungu indigo premium dengan estetika modern, kreatif, dan eksklusif.',
            'primary_color' => '#6d28d9',
            'preview_colors' => ['#6d28d9', '#191428', '#f6f4fa', '#e2dcee'],
            'badge' => 'Executive Light',
        ],
        'amethyst-dark' => [
            'id' => 'amethyst-dark',
            'name' => 'Amethyst Night',
            'mode' => 'dark',
            'caption' => 'Cyberpunk Violet (Dark)',
            'description' => 'Mode gelap bernuansa futuristik dengan latar deep violet dan aksen neon amethyst.',
            'primary_color' => '#a855f7',
            'preview_colors' => ['#a855f7', '#faf5ff', '#110d22', '#352a5c'],
            'badge' => 'Executive Dark',
        ],
        'sunset-amber' => [
            'id' => 'sunset-amber',
            'name' => 'Sunset Amber',
            'mode' => 'light',
            'caption' => 'Warm Terracotta (Light)',
            'description' => 'Sentuhan hangat terracotta & amber dengan kontras tinggi yang nyaman di mata.',
            'primary_color' => '#c2410c',
            'preview_colors' => ['#c2410c', '#231815', '#faf6f4', '#ebdcd6'],
            'badge' => 'Warm Light',
        ],
    ];

    public function __construct(
        protected ConfigService $configService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableThemes(): array
    {
        return array_values(self::THEMES);
    }

    /**
     * Get the active theme key for the tenant (or fallback to default)
     */
    public function getCurrentTheme(?int $userId = null): string
    {
        $theme = $this->configService->get('THEME', 'active_theme', null, null, $userId);

        if (is_string($theme) && array_key_exists($theme, self::THEMES)) {
            return $theme;
        }

        return self::DEFAULT_THEME;
    }

    /**
     * Set the active theme key for the tenant
     */
    public function setTenantTheme(string $themeKey, ?int $userId = null): void
    {
        if (! array_key_exists($themeKey, self::THEMES)) {
            throw new \InvalidArgumentException("Tema tidak valid: {$themeKey}");
        }

        $this->configService->set(
            constGroup: 'THEME',
            groupCode: 'active_theme',
            value: $themeKey,
            applId: null,
            groupId: null,
            userId: $userId,
            valueType: 'text'
        );
    }
}
