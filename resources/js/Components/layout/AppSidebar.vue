<!-- ponytail: Sidebar from shared navMenus supporting up to 3 levels of nested accordion submenus & direct link navigation -->
<script setup lang="ts">
import { computed, inject, nextTick, onMounted, ref, watch, type Component, type Ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronRight, HelpCircle, X } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'
import * as LucideIcons from 'lucide-vue-next'
import { getSavedOpenMenus, getSavedScroll, setSavedOpenMenus, setSavedScroll } from '@/State/sidebarNav'

// AppLayout is wrapped inline per-page (not an Inertia persistent layout), so every visit
// destroys and recreates this component. Note: state can't live in a `let` here even though
// this looks like module scope — everything in a `<script setup>` block compiles into the
// component's setup() function body, so it re-initializes on every mount just like a `ref`
// would. @/State/sidebarNav.ts is a real, separately-evaluated module, so state kept there
// genuinely persists across remounts — that's what makes the sidebar's scroll position and
// manually-expanded accordions carry over across navigations instead of resetting.

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

const page = usePage()
const openMenus = ref<Record<string, boolean>>({ ...getSavedOpenMenus() })
const navRef = ref<HTMLElement | null>(null)

const mobileSidebar = inject<{
  isOpen: Ref<boolean>
  toggle: () => void
  close: () => void
} | null>('mobileSidebar', null)

const normalizePath = (url: string) => {
  if (!url || url === '#') return ''
  try {
    const p = url.startsWith('http') ? new URL(url).pathname : url.split('?')[0].split('#')[0]
    return p.replace(/\/+$/, '') || '/'
  } catch {
    return ''
  }
}

const isItemActive = (href: string) => {
  const target = normalizePath(href)
  if (!target) return false
  const current = normalizePath(page.url)

  if (target === '/dashboard') {
    return current === '/dashboard'
  }
  return current === target || current.startsWith(target + '/')
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

const isSubmenuOpen = (code: string) => {
  return !!openMenus.value[code]
}

const toggleSubmenu = (code: string) => {
  openMenus.value = {
    ...openMenus.value,
    [code]: !openMenus.value[code],
  }
}

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

// Auto-expand active module and nested sub-modules based on current URL
watch(
  () => page.url,
  () => {
    const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
    const updated: Record<string, boolean> = { ...openMenus.value }

    for (const item of items) {
      if (item.children && item.children.length > 0 && isParentActive(item)) {
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

// Persist accordion state across the remounts every Inertia navigation causes — a menu the
// user expanded by hand stays expanded on the next page.
watch(openMenus, (value) => { setSavedOpenMenus(value) }, { deep: true })

// Restore scroll position after the remount. openMenus is already correct by mount time (the
// `immediate` watch above runs during setup(), before this), so the nav's scrollHeight is
// final and one nextTick is enough — no need to wait a frame further.
onMounted(() => {
  nextTick(() => {
    if (navRef.value) navRef.value.scrollTop = getSavedScroll()
  })
})

const onNavScroll = (e: Event) => {
  setSavedScroll((e.target as HTMLElement).scrollTop)
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
</script>

<template>
  <!-- Desktop Sidebar -->
  <aside class="hidden md:flex flex-col w-64 border-r border-border bg-surface-0 h-full min-h-0 shrink-0 select-none">
    <div class="border-b border-border px-4 py-3 space-y-2">
      <div class="px-1 text-xs font-semibold uppercase tracking-wider text-ink-500">
        Qualiv ERP
      </div>
      <TenantSwitcher />
    </div>

    <nav ref="navRef" class="min-h-0 flex-1 overflow-y-auto p-3 space-y-4" @scroll.passive="onNavScroll">
      <div v-for="section in menuSections" :key="section.header" class="space-y-1">
        <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
          {{ section.header }}
        </p>

        <div v-for="item in section.items" :key="item.code" class="space-y-0.5">
          <!-- Level 1: Accordion Menu with Children -->
          <div v-if="item.children && item.children.length > 0">
            <button
              type="button"
              class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150 cursor-pointer focus:outline-none select-none text-left"
              :class="isParentActive(item)
                ? 'bg-accent/10 text-accent font-semibold'
                : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
              :aria-expanded="isSubmenuOpen(item.code)"
              @click="toggleSubmenu(item.code)"
            >
              <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <component
                  :is="getIcon(item.icon)"
                  class="h-4 w-4 shrink-0 transition-colors"
                  :class="isParentActive(item) ? 'text-accent' : 'text-ink-500'"
                />
                <span class="truncate">{{ item.label }}</span>
              </div>

              <ChevronRight
                class="h-4 w-4 shrink-0 transition-transform duration-200"
                :class="{
                  'rotate-90 text-accent': isSubmenuOpen(item.code),
                  'text-ink-400': !isSubmenuOpen(item.code),
                }"
              />
            </button>

            <!-- Level 2 Submenu List -->
            <div
              v-show="isSubmenuOpen(item.code)"
              class="mt-1 ml-3.5 pl-2.5 border-l-2 border-accent/25 space-y-0.5 py-0.5"
            >
              <div v-for="child in item.children" :key="child.code" class="space-y-0.5">
                <!-- Level 2 Accordion with Level 3 Children -->
                <div v-if="child.children && child.children.length > 0">
                  <button
                    type="button"
                    class="w-full flex items-center justify-between px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors duration-150 cursor-pointer focus:outline-none select-none text-left"
                    :class="isLevel2Active(child)
                      ? 'bg-accent/15 text-accent font-semibold'
                      : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
                    :aria-expanded="isSubmenuOpen(child.code)"
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
                      <span class="truncate">{{ child.label }}</span>
                    </div>

                    <ChevronRight
                      class="h-3.5 w-3.5 shrink-0 transition-transform duration-200"
                      :class="{
                        'rotate-90 text-accent': isSubmenuOpen(child.code),
                        'text-ink-400': !isSubmenuOpen(child.code),
                      }"
                    />
                  </button>

                  <!-- Level 3 Sub-item List -->
                  <div
                    v-show="isSubmenuOpen(child.code)"
                    class="mt-1 ml-3 pl-2.5 border-l border-accent/20 space-y-0.5 py-0.5"
                  >
                    <Link
                      v-for="grandchild in child.children"
                      :key="grandchild.code"
                      :href="grandchild.href"
                      class="flex items-center gap-2 px-2 py-1 text-[11px] font-medium rounded-md transition-all duration-150"
                      :class="isItemActive(grandchild.href)
                        ? 'bg-accent text-accent-text font-semibold shadow-xs'
                        : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
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
                      <span class="truncate">{{ grandchild.label }}</span>
                    </Link>
                  </div>
                </div>

                <!-- Level 2 Direct Link (No Children) -->
                <Link
                  v-else
                  :href="child.href"
                  class="flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                  :class="isItemActive(child.href)
                    ? 'bg-accent text-accent-text font-semibold shadow-xs'
                    : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
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
                  <span class="truncate">{{ child.label }}</span>
                </Link>
              </div>
            </div>
          </div>

          <!-- Level 1: Single Item without Children -->
          <Link
            v-else
            :href="item.href"
            class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150"
            :class="isItemActive(item.href)
              ? 'bg-accent/10 text-accent font-semibold shadow-2xs'
              : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
          >
            <component
              :is="getIcon(item.icon)"
              class="h-4 w-4 shrink-0"
              :class="isItemActive(item.href) ? 'text-accent' : 'text-ink-500'"
            />
            <span class="truncate">{{ item.label }}</span>
          </Link>
        </div>
      </div>

      <p v-if="menuSections.length === 0" class="px-3 py-2 text-xs text-ink-600">
        No menus assigned
      </p>
    </nav>
  </aside>

  <!-- Mobile Off-Canvas Drawer -->
  <Teleport to="body">
    <div v-if="mobileSidebar?.isOpen.value" class="fixed inset-0 z-50 md:hidden flex">
      <!-- Backdrop -->
      <div
        class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
        @click="mobileSidebar?.close()"
      />

      <!-- Drawer Panel -->
      <aside
        class="relative flex h-full min-h-0 w-72 max-w-[85vw] flex-col bg-surface-0 border-r border-border shadow-2xl z-10 select-none"
      >
        <div class="border-b border-border px-4 py-3 flex items-center justify-between">
          <div class="px-1 text-xs font-semibold uppercase tracking-wider text-ink-500">
            Qualiv ERP
          </div>
          <button
            type="button"
            class="p-1 rounded-md text-ink-600 hover:text-ink-900 hover:bg-surface-50 focus:outline-none"
            aria-label="Close sidebar"
            @click="mobileSidebar?.close()"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="border-b border-border px-4 py-2.5">
          <TenantSwitcher />
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto p-3 space-y-4">
          <div v-for="section in menuSections" :key="section.header" class="space-y-1">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
              {{ section.header }}
            </p>

            <div v-for="item in section.items" :key="item.code" class="space-y-0.5">
              <!-- Level 1: Accordion Menu with Children -->
              <div v-if="item.children && item.children.length > 0">
                <button
                  type="button"
                  class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150 cursor-pointer focus:outline-none select-none text-left"
                  :class="isParentActive(item)
                    ? 'bg-accent/10 text-accent font-semibold'
                    : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
                  :aria-expanded="isSubmenuOpen(item.code)"
                  @click="toggleSubmenu(item.code)"
                >
                  <div class="flex items-center gap-2.5 min-w-0 flex-1">
                    <component
                      :is="getIcon(item.icon)"
                      class="h-4 w-4 shrink-0 transition-colors"
                      :class="isParentActive(item) ? 'text-accent' : 'text-ink-500'"
                    />
                    <span class="truncate">{{ item.label }}</span>
                  </div>

                  <ChevronRight
                    class="h-4 w-4 shrink-0 transition-transform duration-200"
                    :class="{
                      'rotate-90 text-accent': isSubmenuOpen(item.code),
                      'text-ink-400': !isSubmenuOpen(item.code),
                    }"
                  />
                </button>

                <!-- Level 2 Submenu List -->
                <div
                  v-show="isSubmenuOpen(item.code)"
                  class="mt-1 ml-3.5 pl-2.5 border-l-2 border-accent/25 space-y-0.5 py-0.5"
                >
                  <div v-for="child in item.children" :key="child.code" class="space-y-0.5">
                    <!-- Level 2 Accordion with Level 3 Children -->
                    <div v-if="child.children && child.children.length > 0">
                      <button
                        type="button"
                        class="w-full flex items-center justify-between px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors duration-150 cursor-pointer focus:outline-none select-none text-left"
                        :class="isLevel2Active(child)
                          ? 'bg-accent/15 text-accent font-semibold'
                          : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
                        :aria-expanded="isSubmenuOpen(child.code)"
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
                          <span class="truncate">{{ child.label }}</span>
                        </div>

                        <ChevronRight
                          class="h-3.5 w-3.5 shrink-0 transition-transform duration-200"
                          :class="{
                            'rotate-90 text-accent': isSubmenuOpen(child.code),
                            'text-ink-400': !isSubmenuOpen(child.code),
                          }"
                        />
                      </button>

                      <!-- Level 3 Sub-item List -->
                      <div
                        v-show="isSubmenuOpen(child.code)"
                        class="mt-1 ml-3 pl-2.5 border-l border-accent/20 space-y-0.5 py-0.5"
                      >
                        <Link
                          v-for="grandchild in child.children"
                          :key="grandchild.code"
                          :href="grandchild.href"
                          class="flex items-center gap-2 px-2 py-1 text-[11px] font-medium rounded-md transition-all duration-150"
                          :class="isItemActive(grandchild.href)
                            ? 'bg-accent text-accent-text font-semibold shadow-xs'
                            : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
                          @click="mobileSidebar?.close()"
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
                          <span class="truncate">{{ grandchild.label }}</span>
                        </Link>
                      </div>
                    </div>

                    <!-- Level 2 Direct Link (No Children) -->
                    <Link
                      v-else
                      :href="child.href"
                      class="flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                      :class="isItemActive(child.href)
                        ? 'bg-accent text-accent-text font-semibold shadow-xs'
                        : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
                      @click="mobileSidebar?.close()"
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
                      <span class="truncate">{{ child.label }}</span>
                    </Link>
                  </div>
                </div>
              </div>

              <!-- Level 1: Single Item without Children -->
              <Link
                v-else
                :href="item.href"
                class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150"
                :class="isItemActive(item.href)
                  ? 'bg-accent/10 text-accent font-semibold shadow-2xs'
                  : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
                @click="mobileSidebar?.close()"
              >
                <component
                  :is="getIcon(item.icon)"
                  class="h-4 w-4 shrink-0"
                  :class="isItemActive(item.href) ? 'text-accent' : 'text-ink-500'"
                />
                <span class="truncate">{{ item.label }}</span>
              </Link>
            </div>
          </div>

          <p v-if="menuSections.length === 0" class="px-3 py-2 text-xs text-ink-600">
            No menus assigned
          </p>
        </nav>
      </aside>
    </div>
  </Teleport>
</template>
