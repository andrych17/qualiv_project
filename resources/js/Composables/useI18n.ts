import { computed, watchEffect } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

export interface LocaleDef {
  code: 'id' | 'en'
  name: string
  native: string
  flag: string
  date_locale: string
}

export function useI18n() {
  const page = usePage()

  const currentLocale = computed<'id' | 'en'>(() => {
    return ((page.props as any).locale as 'id' | 'en') || 'id'
  })

  // Keep DOM <html lang="..."> attribute in sync for accessibility and dynamic formatters
  if (typeof document !== 'undefined') {
    watchEffect(() => {
      document.documentElement.setAttribute('lang', currentLocale.value)
    })
  }

  const availableLocales = computed<LocaleDef[]>(() => {
    return ((page.props as any).availableLocales as LocaleDef[]) || [
      { code: 'id', name: 'Bahasa Indonesia', native: 'Bahasa Indonesia', flag: '🇮🇩', date_locale: 'id_ID' },
      { code: 'en', name: 'English', native: 'English', flag: '🇬🇧', date_locale: 'en_US' },
    ]
  })

  const currentLocaleObj = computed<LocaleDef>(() => {
    return availableLocales.value.find((l) => l.code === currentLocale.value) ?? availableLocales.value[0]
  })

  const translations = computed<Record<string, string>>(() => {
    return ((page.props as any).translations as Record<string, string>) || {}
  })

  /**
   * Translate a key with optional parameter replacements.
   * Example: t('common.save') or t('profile.welcome_user', { name: 'Admin' })
   */
  const t = (key: string, replacements?: Record<string, string | number>): string => {
    let message = translations.value[key] ?? key

    if (replacements) {
      // Sort keys by length descending so longer parameters (e.g. :total) are replaced before shorter prefixes (e.g. :to)
      const sortedEntries = Object.entries(replacements).sort((a, b) => b[0].length - a[0].length)
      sortedEntries.forEach(([param, value]) => {
        message = message.replace(new RegExp(`:${param}(?![a-zA-Z0-9_])`, 'g'), String(value))
        message = message.replace(new RegExp(`\\{${param}\\}`, 'g'), String(value))
      })
    }

    return message
  }

  /**
   * Set and persist active locale.
   */
  const setLocale = (localeCode: 'id' | 'en') => {
    router.post(
      route('user.locale.update'),
      { locale: localeCode },
      {
        preserveScroll: true,
        preserveState: false, // Fresh props fetch so all components receive new translations
      }
    )
  }

  return {
    currentLocale,
    currentLocaleObj,
    availableLocales,
    t,
    setLocale,
  }
}
