import { ref, watch, onMounted, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

export interface ThemeDef {
  id: string
  name: string
  mode: 'light' | 'dark'
  caption: string
  description: string
  primary_color: string
  contrast_text?: string
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
        description: 'Tema enterprise standar dengan aksen royal navy yang terpercaya, berwibawa, dan kontras tinggi.',
        primary_color: '#1f5fbf',
        contrast_text: '#ffffff',
        preview_colors: ['#1f5fbf', '#0f172a', '#ffffff', '#cbd5e1'],
        badge: 'Default Light',
      },
      {
        id: 'midnight-dark',
        name: 'Midnight Obsidian',
        mode: 'dark',
        caption: 'Enterprise Obsidian (Dark)',
        description: 'Mode gelap enterprise dengan latar deep slate void dan aksen electric sky blue yang tajam, elegan, dan nyaman di mata.',
        primary_color: '#38bdf8',
        contrast_text: '#0b1329',
        preview_colors: ['#38bdf8', '#f8fafc', '#111827', '#1f293d'],
        badge: 'Default Dark',
      },
      {
        id: 'emerald-horizon',
        name: 'Emerald Horizon',
        mode: 'light',
        caption: 'Legal & Compliance (Light)',
        description: 'Nuansa hijau British racing emerald yang prestisius dan tenang, dioptimalkan untuk firma hukum, perizinan, dan kepatuhan.',
        primary_color: '#047857',
        contrast_text: '#ffffff',
        preview_colors: ['#047857', '#062b20', '#ffffff', '#cfe2d8'],
        badge: 'Legal Light',
      },
      {
        id: 'forest-dark',
        name: 'Forest Night',
        mode: 'dark',
        caption: 'Deep Forest & Legal (Dark)',
        description: 'Mode gelap hutan tropis dengan latar deep pine dan aksen luminous mint bercahaya dengan kontras teks maksimal.',
        primary_color: '#10b981',
        contrast_text: '#052018',
        preview_colors: ['#10b981', '#ecfdf5', '#071712', '#184337'],
        badge: 'Legal Dark',
      },
      {
        id: 'royal-amethyst',
        name: 'Royal Amethyst',
        mode: 'light',
        caption: 'Executive & Innovation (Light)',
        description: 'Aksen ungu indigo premium dengan estetika modern, kreatif, dan eksklusif untuk jajaran manajemen dan tim produk.',
        primary_color: '#6d28d9',
        contrast_text: '#ffffff',
        preview_colors: ['#6d28d9', '#19112e', '#ffffff', '#e2dcf0'],
        badge: 'Executive Light',
      },
      {
        id: 'amethyst-dark',
        name: 'Amethyst Night',
        mode: 'dark',
        caption: 'Cyberpunk Violet (Dark)',
        description: 'Mode gelap bernuansa futuristik dengan latar deep violet void dan aksen neon amethyst yang tajam dan dinamis.',
        primary_color: '#a855f7',
        contrast_text: '#ffffff',
        preview_colors: ['#a855f7', '#faf5ff', '#0d0b18', '#2c234e'],
        badge: 'Executive Dark',
      },
      {
        id: 'sunset-amber',
        name: 'Sunset Amber',
        mode: 'light',
        caption: 'Warm Terracotta (Light)',
        description: 'Sentuhan hangat terracotta & amber yang ramah dan energetik untuk properti, konstruksi, dan agensi kreatif.',
        primary_color: '#c2410c',
        contrast_text: '#ffffff',
        preview_colors: ['#c2410c', '#2a1711', '#ffffff', '#eadcd5'],
        badge: 'Warm Light',
      },
      {
        id: 'terracotta-dark',
        name: 'Terracotta Night',
        mode: 'dark',
        caption: 'Warm Espresso & Amber (Dark)',
        description: 'Mode gelap bertema warm espresso dengan aksen glowing amber flame yang nyaman dan kontras di kondisi minim cahaya.',
        primary_color: '#f97316',
        contrast_text: '#1a0c06',
        preview_colors: ['#f97316', '#fff7ed', '#140f0d', '#3d2c26'],
        badge: 'Warm Dark',
      },
      {
        id: 'swiss-titanium',
        name: 'Swiss Titanium',
        mode: 'light',
        caption: 'Monochrome Minimalist (Light)',
        description: 'Gaya tipografi Swiss dengan palet monokromatik netral berkontras ultra-tinggi untuk akuntansi, audit, dan data analyst.',
        primary_color: '#1e293b',
        contrast_text: '#ffffff',
        preview_colors: ['#1e293b', '#0f172a', '#ffffff', '#cbd5e1'],
        badge: 'Minimal Light',
      },
      {
        id: 'titanium-dark',
        name: 'Titanium Dark',
        mode: 'dark',
        caption: 'Graphite Monolith (Dark)',
        description: 'Mode gelap monokromatik minimalis dengan latar pure graphite dan aksen stark platinum yang bersih dan terfokus pada angka.',
        primary_color: '#f8fafc',
        contrast_text: '#0f172a',
        preview_colors: ['#f8fafc', '#ffffff', '#121212', '#2e2e2e'],
        badge: 'Minimal Dark',
      },
      {
        id: 'oceanic-cobalt',
        name: 'Oceanic Cobalt',
        mode: 'light',
        caption: 'Logistics & Fintech (Light)',
        description: 'Nuansa biru laut dalam yang presisi dan segar untuk supply chain, logistik kargo maritim, dan keuangan global.',
        primary_color: '#0284c7',
        contrast_text: '#ffffff',
        preview_colors: ['#0284c7', '#081d38', '#ffffff', '#cce3fd'],
        badge: 'Fintech Light',
      },
      {
        id: 'abyss-dark',
        name: 'Abyss Night',
        mode: 'dark',
        caption: 'Deep Oceanic Abyss (Dark)',
        description: 'Mode gelap palung samudera dengan latar deep oceanic abyss dan aksen cyan neon yang bercahaya tajam dan modern.',
        primary_color: '#38bdf8',
        contrast_text: '#07111e',
        preview_colors: ['#38bdf8', '#f0f9ff', '#07111e', '#17345b'],
        badge: 'Fintech Dark',
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
        route('user.theme.update'),
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
      'sunset-amber': 'terracotta-dark',
      'terracotta-dark': 'sunset-amber',
      'swiss-titanium': 'titanium-dark',
      'titanium-dark': 'swiss-titanium',
      'oceanic-cobalt': 'abyss-dark',
      'abyss-dark': 'oceanic-cobalt',
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
