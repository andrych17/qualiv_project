<?php

namespace App\Modules\SysConfig\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class LocaleService
{
    /**
     * Supported locales in Nusaevo ERP.
     *
     * @var array<string, array{code: string, name: string, native: string, flag: string, date_locale: string}>
     */
    public const SUPPORTED_LOCALES = [
        'id' => [
            'code' => 'id',
            'name' => 'Bahasa Indonesia',
            'native' => 'Bahasa Indonesia',
            'flag' => '🇮🇩',
            'date_locale' => 'id_ID',
        ],
        'en' => [
            'code' => 'en',
            'name' => 'English',
            'native' => 'English',
            'flag' => '🇬🇧',
            'date_locale' => 'en_US',
        ],
    ];

    /**
     * In-memory runtime cache for merged translations per locale.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $translationCache = [];

    public function __construct(
        protected ConfigService $configService,
    ) {}

    /**
     * @return list<array{code: string, name: string, native: string, flag: string, date_locale: string}>
     */
    public function getAvailableLocales(): array
    {
        return array_values(self::SUPPORTED_LOCALES);
    }

    /**
     * Resolves the effective locale following the 4-tier hierarchy:
     * 1. Authenticated user's preference (`users.locale`)
     * 2. Session / Cookie (`session('locale')`)
     * 3. Tenant default configuration (`SYSCONFIG` `LOCALE.default_language`)
     * 4. System fallback (`config('app.fallback_locale')` / 'id')
     */
    public function resolveLocale(?Request $request = null): string
    {
        $request = $request ?? request();

        // 1. Authenticated user's preference
        if (tenancy()->initialized && $user = $request->user()) {
            if (! empty($user->locale) && array_key_exists($user->locale, self::SUPPORTED_LOCALES)) {
                return $user->locale;
            }
        }

        // 2. Session / Cookie
        if ($request->hasSession() && $request->session()->has('locale')) {
            $sessionLocale = $request->session()->get('locale');
            if (is_string($sessionLocale) && array_key_exists($sessionLocale, self::SUPPORTED_LOCALES)) {
                return $sessionLocale;
            }
        }

        // 3. Tenant default configuration from SYSCONFIG
        if (tenancy()->initialized) {
            $tenantDefault = $this->configService->get('LOCALE', 'default_language');
            if (is_string($tenantDefault) && array_key_exists($tenantDefault, self::SUPPORTED_LOCALES)) {
                return $tenantDefault;
            }
        }

        // 4. System fallback
        return config('app.fallback_locale', 'id');
    }

    /**
     * Persist user's chosen locale.
     */
    public function setUserLocale(User $user, string $locale): void
    {
        if (! array_key_exists($locale, self::SUPPORTED_LOCALES)) {
            throw new \InvalidArgumentException("Locale tidak didukung: {$locale}");
        }

        $user->update(['locale' => $locale]);

        if (request()->hasSession()) {
            request()->session()->put('locale', $locale);
        }

        App::setLocale($locale);
    }

    /**
     * Get merged dictionary translations for the given locale across
     * root lang/ and all isolated app/Modules/{Module}/Lang/ directories.
     * Cached with Laravel Cache to eliminate repetitive disk I/O on every HTTP request.
     *
     * @return array<string, string>
     */
    public function getTranslations(string $locale): array
    {
        if (isset(self::$translationCache[$locale])) {
            return self::$translationCache[$locale];
        }

        $cached = Cache::remember("app_translations_{$locale}", 86400, function () use ($locale) {
            $merged = [];

            // 1. Root / Shared translations (lang/{locale}.json)
            $rootPath = lang_path("{$locale}.json");
            if (File::exists($rootPath)) {
                $decoded = json_decode(File::get($rootPath), true);
                if (is_array($decoded)) {
                    $merged = array_merge($merged, $decoded);
                }
            }

            // 2. Discover all isolated module translations (app/Modules/*/Lang/{locale}.json)
            $modulesPath = app_path('Modules');
            if (File::isDirectory($modulesPath)) {
                $moduleDirs = File::directories($modulesPath);
                foreach ($moduleDirs as $moduleDir) {
                    $moduleLangFile = $moduleDir.DIRECTORY_SEPARATOR.'Lang'.DIRECTORY_SEPARATOR."{$locale}.json";
                    if (File::exists($moduleLangFile)) {
                        $decoded = json_decode(File::get($moduleLangFile), true);
                        if (is_array($decoded)) {
                            $merged = array_merge($merged, $decoded);
                        }
                    }
                }
            }

            return $merged;
        });

        self::$translationCache[$locale] = $cached;

        return $cached;
    }

    /**
     * Clear runtime and persistent translation cache.
     */
    public static function clearCache(): void
    {
        self::$translationCache = [];
        foreach (array_keys(self::SUPPORTED_LOCALES) as $locale) {
            Cache::forget("app_translations_{$locale}");
        }
    }

    /**
     * Register all module JSON translation paths with Laravel's Translator
     * so that backend helpers like __('legal.cases') work seamlessly.
     */
    public static function registerModuleJsonPaths(): void
    {
        $translator = app('translator');
        $modulesPath = app_path('Modules');

        if (! File::isDirectory($modulesPath)) {
            return;
        }

        foreach (File::directories($modulesPath) as $moduleDir) {
            $langDir = $moduleDir.DIRECTORY_SEPARATOR.'Lang';
            if (File::isDirectory($langDir)) {
                $translator->addJsonPath($langDir);
            }
        }
    }
}
