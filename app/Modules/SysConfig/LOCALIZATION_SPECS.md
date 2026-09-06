# User Preferences Specification: Multi-Language (i18n) & Visual Theme (Theme) Per-User
## Bilingual Support (ID/EN) & Unrestricted Per-User Theme Personalization in Nusaevo ERP

---

# 1. Background & Business Motivation

Nusaevo ERP provides tailored vertical editions across diverse industries. To maximize user productivity and ergonomics:
1. **Language Preferences**: Support for both **Indonesian (`id`)** and **English (`en`)** saved per user.
2. **Theme Preferences**: Visual color schemes and dark/light modes should be a personal preference per user, without requiring administrative permissions (`CONFIG_THEME`).

### Client Requirements:
- **Per-User Persistence**: Both `locale` and `theme` are stored on the user's account in the tenant database.
- **Unrestricted Access**: Every logged-in user can change their active theme and language directly from the header switcher or profile settings without needing special access rights.
- **Smart Fallback Hierarchy**:
  - *User Preference* > *Tenant Default* (`SYSCONFIG`) > *Session/Cookie* > *System Default*.
- **Instant Reactive Updates**: Theme and language changes take effect immediately across Vue 3 components and formatters.

---

# 2. Database Schema & Migration

### Tenant `users` table:
File: `database/migrations/tenant/2026_09_06_000001_add_locale_and_theme_to_users_table.php`

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('locale', 10)->default('id')->after('email');
    $table->string('theme', 50)->default('classic-navy')->after('locale');
});
```

---

# 3. Technical Implementation

1. **Header Switchers**:
   - `ThemeSwitcher.vue`: Removed `canManageTheme` gate so all users can switch themes.
   - `LanguageSwitcher.vue`: Quick toggle for Indonesian (🇮🇩) and English (🇬🇧).
2. **User Profile**:
   - `UpdateProfileInformationForm.vue`: Select inputs for display language and theme palette.
3. **Backend Services & Routing**:
   - `LocaleService` & `ThemeService` updated to resolve and store per-user settings.
   - Endpoints: `POST /user/locale` and `POST /user/theme` (accessible to all authenticated users).
4. **Modular Language Isolation & JSON Error Messages**:
   - Each module in `app/Modules/<Module>/Lang/` houses its own domain-specific `id.json` and `en.json`.
   - Core / shared strings remain in root `lang/id.json` & `lang/en.json`, including comprehensive `error.*` (unauthorized, forbidden, not_found, validation_failed, csrf_expired, record_locked), `messages.*` (saved, deleted, created, updated, posted, approved), and `validation.*` rules.
   - `LocaleService::registerModuleJsonPaths()` registers all module `Lang/` folders with Laravel's translator so backend `__('legal.matter')` and frontend `t('legal.matter')` share identical dictionaries.

