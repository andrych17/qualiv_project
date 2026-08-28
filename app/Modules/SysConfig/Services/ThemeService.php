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
            'description' => 'Tema enterprise standar dengan aksen royal navy yang terpercaya, berwibawa, dan kontras tinggi.',
            'primary_color' => '#1f5fbf',
            'preview_colors' => ['#1f5fbf', '#0f172a', '#ffffff', '#e2e8f0'],
            'badge' => 'Default Light',
        ],
        'midnight-dark' => [
            'id' => 'midnight-dark',
            'name' => 'Midnight Obsidian',
            'mode' => 'dark',
            'caption' => 'Enterprise Obsidian (Dark)',
            'description' => 'Mode gelap enterprise dengan latar deep slate void dan aksen electric sky blue yang tajam, elegan, dan nyaman di mata.',
            'primary_color' => '#38bdf8',
            'preview_colors' => ['#38bdf8', '#f8fafc', '#111827', '#1f293d'],
            'badge' => 'Default Dark',
        ],
        'emerald-horizon' => [
            'id' => 'emerald-horizon',
            'name' => 'Emerald Horizon',
            'mode' => 'light',
            'caption' => 'Legal & Compliance (Light)',
            'description' => 'Nuansa hijau British racing emerald yang prestisius dan tenang, dioptimalkan untuk firma hukum, perizinan, dan kepatuhan.',
            'primary_color' => '#047857',
            'preview_colors' => ['#047857', '#062b20', '#ffffff', '#cfe2d8'],
            'badge' => 'Legal Light',
        ],
        'forest-dark' => [
            'id' => 'forest-dark',
            'name' => 'Forest Night',
            'mode' => 'dark',
            'caption' => 'Deep Forest & Legal (Dark)',
            'description' => 'Mode gelap hutan tropis dengan latar deep pine dan aksen luminous mint bercahaya dengan kontras teks maksimal.',
            'primary_color' => '#10b981',
            'preview_colors' => ['#10b981', '#ecfdf5', '#071712', '#184337'],
            'badge' => 'Legal Dark',
        ],
        'royal-amethyst' => [
            'id' => 'royal-amethyst',
            'name' => 'Royal Amethyst',
            'mode' => 'light',
            'caption' => 'Executive & Innovation (Light)',
            'description' => 'Aksen ungu indigo premium dengan estetika modern, kreatif, dan eksklusif untuk jajaran manajemen dan tim produk.',
            'primary_color' => '#6d28d9',
            'preview_colors' => ['#6d28d9', '#19112e', '#ffffff', '#e2dcf0'],
            'badge' => 'Executive Light',
        ],
        'amethyst-dark' => [
            'id' => 'amethyst-dark',
            'name' => 'Amethyst Night',
            'mode' => 'dark',
            'caption' => 'Cyberpunk Violet (Dark)',
            'description' => 'Mode gelap bernuansa futuristik dengan latar deep violet void dan aksen neon amethyst yang tajam dan dinamis.',
            'primary_color' => '#a855f7',
            'preview_colors' => ['#a855f7', '#faf5ff', '#0d0b18', '#2c234e'],
            'badge' => 'Executive Dark',
        ],
        'sunset-amber' => [
            'id' => 'sunset-amber',
            'name' => 'Sunset Amber',
            'mode' => 'light',
            'caption' => 'Warm Terracotta (Light)',
            'description' => 'Sentuhan hangat terracotta & amber yang ramah dan energetik untuk properti, konstruksi, dan agensi kreatif.',
            'primary_color' => '#c2410c',
            'preview_colors' => ['#c2410c', '#2a1711', '#ffffff', '#eadcd5'],
            'badge' => 'Warm Light',
        ],
        'terracotta-dark' => [
            'id' => 'terracotta-dark',
            'name' => 'Terracotta Night',
            'mode' => 'dark',
            'caption' => 'Warm Espresso & Amber (Dark)',
            'description' => 'Mode gelap bertema warm espresso dengan aksen glowing amber flame yang nyaman dan kontras di kondisi minim cahaya.',
            'primary_color' => '#f97316',
            'preview_colors' => ['#f97316', '#fff7ed', '#140f0d', '#3d2c26'],
            'badge' => 'Warm Dark',
        ],
        'swiss-titanium' => [
            'id' => 'swiss-titanium',
            'name' => 'Swiss Titanium',
            'mode' => 'light',
            'caption' => 'Monochrome Minimalist (Light)',
            'description' => 'Gaya tipografi Swiss dengan palet monokromatik netral berkontras ultra-tinggi untuk akuntansi, audit, dan data analyst.',
            'primary_color' => '#1e293b',
            'preview_colors' => ['#1e293b', '#0f172a', '#ffffff', '#cbd5e1'],
            'badge' => 'Minimal Light',
        ],
        'titanium-dark' => [
            'id' => 'titanium-dark',
            'name' => 'Titanium Dark',
            'mode' => 'dark',
            'caption' => 'Graphite Monolith (Dark)',
            'description' => 'Mode gelap monokromatik minimalis dengan latar pure graphite dan aksen stark platinum yang bersih dan terfokus pada angka.',
            'primary_color' => '#f8fafc',
            'preview_colors' => ['#f8fafc', '#ffffff', '#121212', '#2e2e2e'],
            'badge' => 'Minimal Dark',
        ],
        'oceanic-cobalt' => [
            'id' => 'oceanic-cobalt',
            'name' => 'Oceanic Cobalt',
            'mode' => 'light',
            'caption' => 'Logistics & Fintech (Light)',
            'description' => 'Nuansa biru laut dalam yang presisi dan segar untuk supply chain, logistik kargo maritim, dan keuangan global.',
            'primary_color' => '#0284c7',
            'preview_colors' => ['#0284c7', '#081d38', '#ffffff', '#cce3fd'],
            'badge' => 'Fintech Light',
        ],
        'abyss-dark' => [
            'id' => 'abyss-dark',
            'name' => 'Abyss Night',
            'mode' => 'dark',
            'caption' => 'Deep Oceanic Abyss (Dark)',
            'description' => 'Mode gelap palung samudera dengan latar deep oceanic abyss dan aksen cyan neon yang bercahaya tajam dan modern.',
            'primary_color' => '#38bdf8',
            'preview_colors' => ['#38bdf8', '#f0f9ff', '#07111e', '#17345b'],
            'badge' => 'Fintech Dark',
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
