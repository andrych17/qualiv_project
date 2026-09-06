<!-- ponytail: The one menu tree. Previously this markup existed three times inside
     AppSidebar.vue (desktop expanded, collapsed rail, mobile drawer), which meant every menu
     change had to be made three times and the mobile copy had already drifted.
     `variant` picks the tree or the collapsed icon rail; `navigate` lets the mobile drawer
     close itself without this component knowing a drawer exists. -->
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronRight, HelpCircle } from 'lucide-vue-next'
import * as LucideIcons from 'lucide-vue-next'
import { getSavedScroll, setSavedScroll, openMenus } from '@/State/sidebarNav'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const menuLabel = (code: string, fallback: string) => {
  const key = `menu.${code}`
  const translated = t(key)
  return translated !== key ? translated : fallback
}

const sectionHeader = (header: string) => {
  const map: Record<string, string> = {
    Main: 'nav.main',
    Core: 'nav.core',
    Operations: 'nav.operations',
    People: 'nav.people',
    System: 'nav.system',
    Vertical: 'nav.vertical',
  }
  const key = map[header] || `nav.${header.toLowerCase()}`
  const translated = t(key)
  return translated !== key ? translated : header
}

type Level3MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
}

type Level2MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  children?: Level3MenuItem[]
}

type MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  header: string | null
  children?: Level2MenuItem[]
}

type MenuSection = {
  header: string
  items: MenuItem[]
}

const props = withDefaults(
  defineProps<{
    variant?: 'tree' | 'rail'
    /** Only the desktop tree restores scroll; the drawer always opens at the top. */
    persistScroll?: boolean
    /** Enforces 44px minimum hit areas. Set by the mobile drawer, the only touch surface. */
    touch?: boolean
  }>(),
  { variant: 'tree', persistScroll: false, touch: false },
)

/** 44px is the minimum comfortable touch target; the desktop tree stays dense. */
const hit = computed(() => (props.touch ? 'min-h-[44px]' : ''))
const hitButton = computed(() => (props.touch ? 'min-h-[44px] min-w-[44px] justify-center' : ''))

const emit = defineEmits<{ (e: 'navigate'): void }>()

const page = usePage()
const navRef = ref<HTMLElement | null>(null)
const activeFlyout = ref<string | null>(null)

const normalizePath = (url: string) => {
  if (!url || url === '#') return ''
  try {
    const p = url.startsWith('http') ? new URL(url).pathname : url.split('?')[0].split('#')[0]
    return p.replace(/\/+$/, '') || '/'
  } catch {
    return ''
  }
}

const allNavTargets = computed(() => {
  const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
  const targets: string[] = []
  const extract = (menuList: Array<{ href?: string; children?: any[] }>) => {
    for (const m of menuList) {
      if (m.href && m.href !== '#') {
        const norm = normalizePath(m.href)
        if (norm) targets.push(norm)
      }
      if (m.children && m.children.length > 0) {
        extract(m.children)
      }
    }
  }
  extract(items)
  return targets
})

const isItemActive = (href: string) => {
  const target = normalizePath(href)
  if (!target) return false
  const current = normalizePath(page.url)

  if (target === '/dashboard') {
    return current === '/dashboard'
  }

  // Exact match
  if (current === target) {
    return true
  }

  // Prefix match: only if no other more specific nav target matches current
  if (current.startsWith(target + '/')) {
    const hasMoreSpecificMatch = allNavTargets.value.some(
      (otherTarget) =>
        otherTarget !== target &&
        otherTarget.length > target.length &&
        (current === otherTarget || current.startsWith(otherTarget + '/'))
    )
    return !hasMoreSpecificMatch
  }

  return false
}

const isLevel2Active = (item: Level2MenuItem) => {
  if (item.children && item.children.length > 0) {
    if (item.children.some((c) => isItemActive(c.href))) {
      return true
    }
  }
  return isItemActive(item.href)
}

const isParentActive = (item: MenuItem) => {
  if (item.children && item.children.length > 0) {
    if (item.children.some((c) => isLevel2Active(c))) {
      return true
    }
  }
  return isItemActive(item.href)
}

const isSubmenuOpen = (code: string) => !!openMenus.value[code]

const menuSections = computed((): MenuSection[] => {
  const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
  const sections: MenuSection[] = []
  const byHeader = new Map<string, MenuSection>()
  for (const item of items) {
    const header = (item.header || 'Main').trim() || 'Main'
    let section = byHeader.get(header)
    if (!section) {
      section = { header, items: [] }
      byHeader.set(header, section)
      sections.push(section)
    }
    section.items.push(item)
  }
  return sections
})
const level1Codes = computed(
  () => new Set(menuSections.value.flatMap((s) => s.items.map((i) => i.code))),
)

/**
 * Single-open accordion at level 1: opening a module collapses the others. With ~20 ERP
 * modules the previous merge-everything behaviour turned the nav into a permanent long scroll,
 * since state persists and every visited module stayed expanded.
 */
const setSubmenu = (code: string, open: boolean) => {
  const next = { ...openMenus.value }
  if (open && level1Codes.value.has(code)) {
    for (const other of level1Codes.value) next[other] = false
  }
  next[code] = open
  openMenus.value = next
}

const toggleSubmenu = (code: string) => setSubmenu(code, !openMenus.value[code])

const openSubmenu = (code: string) => setSubmenu(code, true)


// Auto-expand active module and nested sub-modules based on current URL
watch(
  () => page.url,
  () => {
    const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
    const updated: Record<string, boolean> = { ...openMenus.value }

    for (const item of items) {
      if (item.children && item.children.length > 0 && isParentActive(item)) {
        // Same single-open rule as a manual click, so navigating away from a module
        // collapses it instead of leaving every visited module expanded forever.
        for (const other of items) updated[other.code] = false
        updated[item.code] = true
        for (const child of item.children) {
          if (child.children && child.children.length > 0 && isLevel2Active(child)) {
            updated[child.code] = true
          }
        }
      }
    }
    openMenus.value = updated
  },
  { immediate: true },
)

onMounted(() => {
  if (!props.persistScroll) return
  nextTick(() => {
    if (navRef.value) navRef.value.scrollTop = getSavedScroll()
  })
})

const onNavScroll = (e: Event) => {
  if (props.persistScroll) setSavedScroll((e.target as HTMLElement).scrollTop)
}

const getIcon = (name: string | null): Component => {
  if (!name) return HelpCircle

  const icons = LucideIcons as unknown as Record<string, Component>
  if (icons[name]) return icons[name]

  const pascal = name
    .split(/[-_]/)
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase())
    .join('')
  return icons[pascal] ?? HelpCircle
}

/** A parent row navigates to its own page, or to its first child when it has no page. */
const parentHref = (item: { href?: string; children?: Array<{ href: string }> }) =>
  item.href && item.href !== '#' ? item.href : (item.children?.[0]?.href || '#')

// --- Collapsed rail flyout: hover for mouse, a real menu for keyboard ---
// Hover alone left the whole collapsed sidebar unreachable by keyboard, so the flyout is a
// roving-focus menu: ArrowDown/Up move, Home/End jump, Escape closes and hands focus back to
// the module icon it came from.
// Scoped to the rail <nav> rather than the flyout element itself: a ref placed inside v-for is
// collected into an array by Vue, and only one flyout is ever open, so every [role=menuitem]
// under the rail belongs to it.
const railRef = ref<HTMLElement | null>(null)
const triggerEls = new Map<string, HTMLElement>()

const setTriggerEl = (code: string, el: unknown) => {
  const node = (el as { $el?: HTMLElement } | HTMLElement | null)
  const element = node && '$el' in (node as object) ? (node as { $el: HTMLElement }).$el : (node as HTMLElement | null)
  if (element) triggerEls.set(code, element)
  else triggerEls.delete(code)
}

const flyoutItems = () =>
  Array.from(railRef.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])

const focusFlyoutItem = (index: number) => {
  const items = flyoutItems()
  if (!items.length) return
  items[(index + items.length) % items.length].focus()
}

const openFlyoutWithFocus = async (code: string) => {
  activeFlyout.value = code
  await nextTick()
  focusFlyoutItem(0)
}

const closeFlyout = (returnFocus = false) => {
  const code = activeFlyout.value
  activeFlyout.value = null
  if (returnFocus && code) triggerEls.get(code)?.focus()
}

/** Hover-out must not yank a flyout away from someone tabbing through it. */
const closeFlyoutOnLeave = () => {
  if (!flyoutItems().includes(document.activeElement as HTMLElement)) closeFlyout()
}

const onFlyoutKeydown = (e: KeyboardEvent) => {
  const items = flyoutItems()
  const current = items.indexOf(document.activeElement as HTMLElement)

  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault()
      focusFlyoutItem(current + 1)
      break
    case 'ArrowUp':
      e.preventDefault()
      focusFlyoutItem(current - 1)
      break
    case 'Home':
      e.preventDefault()
      focusFlyoutItem(0)
      break
    case 'End':
      e.preventDefault()
      focusFlyoutItem(items.length - 1)
      break
    case 'Escape':
      e.preventDefault()
      closeFlyout(true)
      break
  }
}

// A flyout opened by hover has no focus inside it, so Escape needs a document-level listener too.
const closeFlyoutOnEscape = (e: KeyboardEvent) => {
  if (e.key === 'Escape') closeFlyout()
}

onMounted(() => {
  if (props.variant === 'rail') window.addEventListener('keydown', closeFlyoutOnEscape)
})

onBeforeUnmount(() => window.removeEventListener('keydown', closeFlyoutOnEscape))
</script>

<template>
  <nav
    v-if="variant === 'tree'"
    ref="navRef"
    aria-label="Menu utama"
    class="min-h-0 flex-1 overflow-y-auto p-3 space-y-4"
    @scroll.passive="onNavScroll"
  >
    <div v-for="section in menuSections" :key="section.header" class="space-y-1">
      <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
        {{ sectionHeader(section.header) }}
      </p>

      <div v-for="item in section.items" :key="item.code" class="space-y-0.5">
        <!-- Level 1: parent row — click anywhere to toggle submenu -->
        <div v-if="item.children && item.children.length > 0">
          <button
            type="button"
            class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150 select-none group text-left cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :class="[hit, isParentActive(item)
              ? 'bg-accent/10 text-accent font-semibold'
              : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900']"
            :aria-expanded="isSubmenuOpen(item.code)"
            :title="isSubmenuOpen(item.code) ? t('common.close') + ' ' + menuLabel(item.code, item.label) : menuLabel(item.code, item.label)"
            @click="toggleSubmenu(item.code)"
          >
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
              <component
                :is="getIcon(item.icon)"
                class="h-4 w-4 shrink-0 transition-colors"
                :class="isParentActive(item) ? 'text-accent' : 'text-ink-500 group-hover:text-ink-700'"
              />
              <span class="truncate">{{ menuLabel(item.code, item.label) }}</span>
            </div>

            <ChevronRight
              class="h-4 w-4 shrink-0 transition-transform duration-200 ml-1"
              :class="{
                'rotate-90 text-accent': isSubmenuOpen(item.code),
                'text-ink-400 group-hover:text-ink-600': !isSubmenuOpen(item.code),
              }"
            />
          </button>

          <!-- Level 2 -->
          <div
            v-show="isSubmenuOpen(item.code)"
            class="mt-1 ml-3.5 pl-2.5 border-l-2 border-accent/25 space-y-0.5 py-0.5"
          >
            <div v-for="child in item.children" :key="child.code" class="space-y-0.5">
              <div v-if="child.children && child.children.length > 0">
                <button
                  type="button"
                  class="w-full flex items-center justify-between px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors duration-150 select-none group text-left cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                  :class="[hit, isLevel2Active(child)
                    ? 'bg-accent/15 text-accent font-semibold'
                    : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900']"
                  :aria-expanded="isSubmenuOpen(child.code)"
                  :title="isSubmenuOpen(child.code) ? t('common.close') + ' ' + menuLabel(child.code, child.label) : menuLabel(child.code, child.label)"
                  @click="toggleSubmenu(child.code)"
                >
                  <div class="flex items-center gap-2 min-w-0 flex-1">
                    <component
                      :is="getIcon(child.icon)"
                      v-if="child.icon"
                      class="h-3.5 w-3.5 shrink-0"
                      :class="isLevel2Active(child) ? 'text-accent' : 'text-ink-400'"
                    />
                    <span
                      v-else
                      class="w-1.5 h-1.5 rounded-full shrink-0"
                      :class="isLevel2Active(child) ? 'bg-accent' : 'bg-ink-400'"
                    />
                    <span class="truncate">{{ menuLabel(child.code, child.label) }}</span>
                  </div>

                  <ChevronRight
                    class="h-3.5 w-3.5 shrink-0 transition-transform duration-200 ml-1"
                    :class="{
                      'rotate-90 text-accent': isSubmenuOpen(child.code),
                      'text-ink-400 group-hover:text-ink-600': !isSubmenuOpen(child.code),
                    }"
                  />
                </button>

                <!-- Level 3 -->
                <div
                  v-show="isSubmenuOpen(child.code)"
                  class="mt-1 ml-3 pl-2.5 border-l border-accent/20 space-y-0.5 py-0.5"
                >
                  <Link
                    v-for="grandchild in child.children"
                    :key="grandchild.code"
                    :href="grandchild.href"
                    class="flex items-center gap-2 px-2 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                    :class="[hit, isItemActive(grandchild.href)
                      ? 'bg-accent text-accent-text font-semibold shadow-xs'
                      : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900']"
                    :aria-current="isItemActive(grandchild.href) ? 'page' : undefined"
                    @click="emit('navigate')"
                  >
                    <component
                      :is="getIcon(grandchild.icon)"
                      v-if="grandchild.icon"
                      class="h-3 w-3 shrink-0"
                      :class="isItemActive(grandchild.href) ? 'text-accent-text' : 'text-ink-400'"
                    />
                    <span
                      v-else
                      class="w-1 h-1 rounded-full shrink-0"
                      :class="isItemActive(grandchild.href) ? 'bg-accent-text' : 'bg-ink-400'"
                    />
                    <span class="truncate">{{ menuLabel(grandchild.code, grandchild.label) }}</span>
                  </Link>
                </div>
              </div>

              <!-- Level 2 direct link -->
              <Link
                v-else
                :href="child.href"
                class="flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                :class="[hit, isItemActive(child.href)
                  ? 'bg-accent text-accent-text font-semibold shadow-xs'
                  : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900']"
                :aria-current="isItemActive(child.href) ? 'page' : undefined"
                @click="emit('navigate')"
              >
                <component
                  :is="getIcon(child.icon)"
                  v-if="child.icon"
                  class="h-3.5 w-3.5 shrink-0"
                  :class="isItemActive(child.href) ? 'text-accent-text' : 'text-ink-400'"
                />
                <span
                  v-else
                  class="w-1.5 h-1.5 rounded-full shrink-0"
                  :class="isItemActive(child.href) ? 'bg-accent-text' : 'bg-ink-400'"
                />
                <span class="truncate">{{ menuLabel(child.code, child.label) }}</span>
              </Link>
            </div>
          </div>
        </div>

        <!-- Level 1 direct link -->
        <Link
          v-else
          :href="item.href"
          class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150"
          :class="[hit, isItemActive(item.href)
            ? 'bg-accent/10 text-accent font-semibold shadow-2xs'
            : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900']"
          :aria-current="isItemActive(item.href) ? 'page' : undefined"
          @click="emit('navigate')"
        >
          <component
            :is="getIcon(item.icon)"
            class="h-4 w-4 shrink-0"
            :class="isItemActive(item.href) ? 'text-accent' : 'text-ink-500'"
          />
          <span class="truncate">{{ menuLabel(item.code, item.label) }}</span>
        </Link>
      </div>
    </div>

    <p v-if="menuSections.length === 0" class="px-3 py-2 text-xs text-ink-600">
      {{ t('common.no_data') }}
    </p>
  </nav>

  <!-- Collapsed icon rail with flyout submenus -->
  <nav
    v-else
    ref="railRef"
    aria-label="Menu utama"
    class="min-h-0 flex-1 overflow-y-auto p-2 space-y-3 flex flex-col items-center"
  >
    <div v-for="section in menuSections" :key="section.header" class="w-full space-y-1">
      <div class="h-px w-6 bg-border mx-auto my-1.5" />

      <div
        v-for="item in section.items"
        :key="item.code"
        class="relative flex justify-center w-full"
        @mouseenter="activeFlyout = item.code"
        @mouseleave="closeFlyoutOnLeave"
        @keydown="onFlyoutKeydown"
      >
        <Link
          :href="parentHref(item)"
          class="h-10 w-10 rounded-lg flex items-center justify-center transition-all duration-150 relative cursor-pointer group focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
          :class="isParentActive(item)
            ? 'bg-accent text-accent-text shadow-xs ring-2 ring-accent/30 font-semibold'
            : 'text-ink-600 hover:text-ink-900 hover:bg-surface-50 border border-transparent hover:border-border'"
          :title="menuLabel(item.code, item.label)"
          :aria-current="isParentActive(item) ? 'page' : undefined"
          :aria-haspopup="item.children && item.children.length > 0 ? 'menu' : undefined"
          :aria-expanded="item.children && item.children.length > 0 ? activeFlyout === item.code : undefined"
          :ref="(el) => setTriggerEl(item.code, el)"
          @focus="activeFlyout = item.code"
          @keydown.down.prevent="openFlyoutWithFocus(item.code)"
        >
          <component :is="getIcon(item.icon)" class="h-5 w-5 shrink-0" />
          <span
            v-if="isParentActive(item)"
            class="absolute -left-2 top-1/2 -translate-y-1/2 w-1 h-5 rounded-r bg-accent"
          />
        </Link>

        <!-- Flyout is anchored bottom-clamped so long module lists do not run off screen. -->
        <div
          v-if="activeFlyout === item.code && item.children && item.children.length > 0"
          role="menu"
          :aria-label="menuLabel(item.code, item.label)"
          class="absolute left-full top-0 ml-2.5 w-60 max-h-[70vh] bg-surface-0 border border-border shadow-2xl rounded-xl p-2 z-50 animate-enter"
        >
          <div class="px-2.5 py-1.5 border-b border-border/50 mb-1 flex items-center justify-between">
            <span class="text-xs font-bold text-ink-900 truncate">{{ menuLabel(item.code, item.label) }}</span>
            <span class="text-[10px] uppercase font-semibold text-ink-500 tracking-wider">{{ sectionHeader(section.header) }}</span>
          </div>

          <div class="space-y-1 max-h-[60vh] overflow-y-auto py-1">
            <template v-for="child in item.children" :key="child.code">
              <div v-if="child.children && child.children.length > 0" class="space-y-0.5">
                <p class="px-2 pt-1 text-[10px] font-bold text-ink-500 uppercase tracking-wider">
                  {{ menuLabel(child.code, child.label) }}
                </p>
                <Link
                  v-for="grandchild in child.children"
                  :key="grandchild.code"
                  :href="grandchild.href"
                  class="flex items-center gap-2 px-2.5 py-1.5 text-xs rounded-md transition-colors"
                  :class="isItemActive(grandchild.href)
                    ? 'bg-accent text-accent-text font-semibold'
                    : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
                  role="menuitem"
                  :aria-current="isItemActive(grandchild.href) ? 'page' : undefined"
                  @click="emit('navigate')"
                >
                  <component :is="getIcon(grandchild.icon)" v-if="grandchild.icon" class="h-3.5 w-3.5 shrink-0" />
                  <span class="truncate">{{ menuLabel(grandchild.code, grandchild.label) }}</span>
                </Link>
              </div>

              <Link
                v-else
                :href="child.href"
                class="flex items-center gap-2 px-2.5 py-1.5 text-xs rounded-md transition-colors"
                :class="isItemActive(child.href)
                  ? 'bg-accent text-accent-text font-semibold'
                  : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
                role="menuitem"
                :aria-current="isItemActive(child.href) ? 'page' : undefined"
                @click="emit('navigate')"
              >
                <component :is="getIcon(child.icon)" v-if="child.icon" class="h-3.5 w-3.5 shrink-0" />
                <span class="truncate">{{ menuLabel(child.code, child.label) }}</span>
              </Link>
            </template>
          </div>
        </div>
      </div>
    </div>
  </nav>
</template>
