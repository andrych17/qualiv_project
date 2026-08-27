import { ref, watch, onMounted, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

export interface ThemeDef {
  id: string
  name: string
  mode: 'light' | 'dark'
  caption: string
  description: string
  primary_color: string
  preview_colors: string[]
  badge?: string
}

export const activeTheme = ref<string>('classic-navy')

export function applyThemeToDom(themeKey: string) {
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('data-theme', themeKey)
    document.body.setAttribute('data-theme', themeKey)
  }
}

export function useTheme() {
  const page = usePage()

  const availableThemes = computed<ThemeDef[]>(() => {
    return (page.props.availableThemes as ThemeDef[]) ?? [
      {
        id: 'classic-navy',
        name: 'Classic Navy',
        mode: 'light',
        caption: 'Enterprise Standard (Light)',
        description: 'Tema klasik enterprise dengan aksen royal navy yang profesional, tenang, dan tegas.',
        primary_color: '#1f5fbf',
        preview_colors: ['#1f5fbf', '#12181f', '#f4f6f8', '#dee3e8'],
        badge: 'Default Light',
      },
      {
        id: 'midnight-dark',
        name: 'Midnight Dark',
        mode: 'dark',
        caption: 'Enterprise Obsidian (Dark)',
        description: 'Mode gelap enterprise dengan latar slate charcoal dan aksen electric sky blue yang tajam dan nyaman di malam hari.',
        primary_color: '#38bdf8',
        preview_colors: ['#38bdf8', '#f8fafc', '#0f172a', '#334155'],
        badge: 'Default Dark',
      },
      {
        id: 'emerald-horizon',
        name: 'Emerald Horizon',
        mode: 'light',
        caption: 'Nature & Legal (Light)',
        description: 'Nuansa hijau emerald elegan yang segar dan berwibawa, cocok untuk firma hukum dan instansi.',
        primary_color: '#0d8a68',
        preview_colors: ['#0d8a68', '#0f1f1a', '#f2f8f5', '#d3e4dc'],
        badge: 'Legal Light',
      },
      {
        id: 'forest-dark',
        name: 'Forest Night',
        mode: 'dark',
        caption: 'Deep Forest (Dark)',
        description: 'Mode gelap bertema hutan tropis dengan aksen mint bercahaya yang kontras dan elegan.',
        primary_color: '#10b981',
        preview_colors: ['#10b981', '#f0fdf4', '#0a1712', '#1e3d31'],
        badge: 'Legal Dark',
      },
      {
        id: 'royal-amethyst',
        name: 'Royal Amethyst',
        mode: 'light',
        caption: 'Executive & Tech (Light)',
        description: 'Aksen ungu indigo premium dengan estetika modern, kreatif, dan eksklusif.',
        primary_color: '#6d28d9',
        preview_colors: ['#6d28d9', '#191428', '#f6f4fa', '#e2dcee'],
        badge: 'Executive Light',
      },
      {
        id: 'amethyst-dark',
        name: 'Amethyst Night',
        mode: 'dark',
        caption: 'Cyberpunk Violet (Dark)',
        description: 'Mode gelap bernuansa futuristik dengan latar deep violet dan aksen neon amethyst.',
        primary_color: '#a855f7',
        preview_colors: ['#a855f7', '#faf5ff', '#110d22', '#352a5c'],
        badge: 'Executive Dark',
      },
      {
        id: 'sunset-amber',
        name: 'Sunset Amber',
        mode: 'light',
        caption: 'Warm Terracotta (Light)',
        description: 'Sentuhan hangat terracotta & amber dengan kontras tinggi yang nyaman di mata.',
        primary_color: '#c2410c',
        preview_colors: ['#c2410c', '#231815', '#faf6f4', '#ebdcd6'],
        badge: 'Warm Light',
      },
    ]
  })

  const currentThemeObj = computed(() => {
    return availableThemes.value.find((t) => t.id === activeTheme.value) ?? availableThemes.value[0]
  })

  const isDark = computed(() => {
    return currentThemeObj.value?.mode === 'dark'
  })

  const syncThemeFromPage = () => {
    const serverTheme = (page.props as any)?.theme
    if (serverTheme && typeof serverTheme === 'string') {
      activeTheme.value = serverTheme
      applyThemeToDom(serverTheme)
    }
  }

  const setTheme = (themeKey: string, persist = true) => {
    activeTheme.value = themeKey
    applyThemeToDom(themeKey)

    if (persist) {
      router.post(
        route('config.theme.update'),
        { theme: themeKey },
        {
          preserveScroll: true,
          preserveState: true,
        }
      )
    }
  }

  const toggleLightDark = (persist = true) => {
    const lightToDarkMap: Record<string, string> = {
      'classic-navy': 'midnight-dark',
      'midnight-dark': 'classic-navy',
      'emerald-horizon': 'forest-dark',
      'forest-dark': 'emerald-horizon',
      'royal-amethyst': 'amethyst-dark',
      'amethyst-dark': 'royal-amethyst',
      'sunset-amber': 'midnight-dark',
    }

    const nextTheme = lightToDarkMap[activeTheme.value] ?? (isDark.value ? 'classic-navy' : 'midnight-dark')
    setTheme(nextTheme, persist)
  }

  onMounted(() => {
    syncThemeFromPage()
  })

  watch(
    () => (page.props as any)?.theme,
    (newTheme) => {
      if (newTheme && typeof newTheme === 'string') {
        activeTheme.value = newTheme
        applyThemeToDom(newTheme)
      }
    },
    { immediate: true }
  )

  return {
    activeTheme,
    availableThemes,
    currentThemeObj,
    isDark,
    setTheme,
    toggleLightDark,
    applyThemeToDom,
  }
}
