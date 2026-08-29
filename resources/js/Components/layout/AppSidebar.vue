<!-- ponytail: Sidebar from shared navMenus with intuitive accordion submenus & direct link navigation -->
<script setup lang="ts">
import { computed, inject, ref, watch, type Component, type Ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronRight, HelpCircle, X } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'
import * as LucideIcons from 'lucide-vue-next'

type SubMenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
}

type MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  header: string | null
  children?: SubMenuItem[]
}

type MenuSection = {
  header: string
  items: MenuItem[]
}

const page = usePage()
const openMenus = ref<Record<string, boolean>>({})

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

const isParentActive = (item: MenuItem) => {
  if (item.children && item.children.length > 0) {
    return item.children.some((c) => isItemActive(c.href))
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

const openSubmenu = (code: string) => {
  openMenus.value = {
    ...openMenus.value,
    [code]: true,
  }
}

const menuSections = computed((): MenuSection[] => {
  const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
  const sections: MenuSection[] = []
  for (const item of items) {
    const header = item.header || 'Main'
    const last = sections[sections.length - 1]
    if (last && last.header === header) {
      last.items.push(item)
    } else {
      sections.push({ header, items: [item] })
    }
  }
  return sections
})

// Auto-expand active module based on current URL
watch(
  () => page.url,
  () => {
    mobileSidebar?.close()
    const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
    const updated: Record<string, boolean> = { ...openMenus.value }

    for (const item of items) {
      if (item.children && item.children.length > 0 && isParentActive(item)) {
        updated[item.code] = true
      }
    }
    openMenus.value = updated
  },
  { immediate: true },
)

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
  <aside class="hidden md:flex flex-col w-64 border-r border-border bg-surface-0 h-screen sticky top-0 shrink-0 select-none">
    <div class="border-b border-border px-4 py-3 space-y-2">
      <div class="px-1 text-xs font-semibold uppercase tracking-wider text-ink-500">
        NusaEvo ERP
      </div>
      <TenantSwitcher />
    </div>

    <nav class="flex-1 overflow-y-auto p-3 space-y-4">
      <div v-for="section in menuSections" :key="section.header" class="space-y-1">
        <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
          {{ section.header }}
        </p>

        <div v-for="item in section.items" :key="item.code" class="space-y-0.5">
          <!-- Accordion Menu with Children -->
          <div v-if="item.children && item.children.length > 0">
            <div
              class="flex items-center justify-between rounded-lg transition-colors duration-150"
              :class="isParentActive(item)
                ? 'bg-accent/10 text-accent font-semibold'
                : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
            >
              <!-- Click title to navigate & expand -->
              <Link
                :href="item.href"
                class="flex items-center gap-2.5 flex-1 min-w-0 px-3 py-2 text-sm font-medium"
                @click="openSubmenu(item.code)"
              >
                <component
                  :is="getIcon(item.icon)"
                  class="h-4 w-4 shrink-0"
                  :class="isParentActive(item) ? 'text-accent' : 'text-ink-500'"
                />
                <span class="truncate">{{ item.label }}</span>
              </Link>

              <!-- Chevron button to toggle accordion in-place -->
              <button
                type="button"
                class="p-2 mr-1 rounded-md text-ink-400 hover:text-ink-700 hover:bg-surface-100/80 cursor-pointer focus:outline-none transition-colors"
                :title="isSubmenuOpen(item.code) ? 'Collapse' : 'Expand'"
                @click.stop.prevent="toggleSubmenu(item.code)"
              >
                <ChevronRight
                  class="h-4 w-4 shrink-0 transition-transform duration-200"
                  :class="{
                    'rotate-90 text-accent': isSubmenuOpen(item.code),
                    'text-ink-400': !isSubmenuOpen(item.code),
                  }"
                />
              </button>
            </div>

            <!-- Submenu Children List -->
            <div
              v-if="isSubmenuOpen(item.code)"
              class="mt-1 ml-4 pl-3 border-l-2 border-accent/25 space-y-0.5 py-0.5"
            >
              <Link
                v-for="child in item.children"
                :key="child.code"
                :href="child.href"
                class="flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                :class="isItemActive(child.href)
                  ? 'bg-accent text-white font-semibold shadow-xs'
                  : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
              >
                <component
                  :is="getIcon(child.icon)"
                  v-if="child.icon"
                  class="h-3.5 w-3.5 shrink-0"
                  :class="isItemActive(child.href) ? 'text-white' : 'text-ink-400'"
                />
                <span
                  v-else
                  class="w-1.5 h-1.5 rounded-full shrink-0"
                  :class="isItemActive(child.href) ? 'bg-white' : 'bg-ink-400'"
                />
                <span class="truncate">{{ child.label }}</span>
              </Link>
            </div>
          </div>

          <!-- Single Item without Children -->
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
        class="relative flex flex-col w-72 max-w-[85vw] h-full bg-surface-0 border-r border-border shadow-2xl z-10 select-none"
      >
        <div class="border-b border-border px-4 py-3 flex items-center justify-between">
          <div class="px-1 text-xs font-semibold uppercase tracking-wider text-ink-500">
            NusaEvo ERP
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

        <nav class="flex-1 overflow-y-auto p-3 space-y-4">
          <div v-for="section in menuSections" :key="section.header" class="space-y-1">
            <p class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">
              {{ section.header }}
            </p>

            <div v-for="item in section.items" :key="item.code" class="space-y-0.5">
              <!-- Accordion Menu with Children -->
              <div v-if="item.children && item.children.length > 0">
                <div
                  class="flex items-center justify-between rounded-lg transition-colors duration-150"
                  :class="isParentActive(item)
                    ? 'bg-accent/10 text-accent font-semibold'
                    : 'text-ink-700 hover:bg-surface-50 hover:text-ink-900'"
                >
                  <Link
                    :href="item.href"
                    class="flex items-center gap-2.5 flex-1 min-w-0 px-3 py-2 text-sm font-medium"
                    @click="openSubmenu(item.code); mobileSidebar?.close()"
                  >
                    <component
                      :is="getIcon(item.icon)"
                      class="h-4 w-4 shrink-0"
                      :class="isParentActive(item) ? 'text-accent' : 'text-ink-500'"
                    />
                    <span class="truncate">{{ item.label }}</span>
                  </Link>

                  <button
                    type="button"
                    class="p-2 mr-1 rounded-md text-ink-400 hover:text-ink-700 hover:bg-surface-100/80 cursor-pointer focus:outline-none transition-colors"
                    :title="isSubmenuOpen(item.code) ? 'Collapse' : 'Expand'"
                    @click.stop.prevent="toggleSubmenu(item.code)"
                  >
                    <ChevronRight
                      class="h-4 w-4 shrink-0 transition-transform duration-200"
                      :class="{
                        'rotate-90 text-accent': isSubmenuOpen(item.code),
                        'text-ink-400': !isSubmenuOpen(item.code),
                      }"
                    />
                  </button>
                </div>

                <!-- Submenu Children List -->
                <div
                  v-if="isSubmenuOpen(item.code)"
                  class="mt-1 ml-4 pl-3 border-l-2 border-accent/25 space-y-0.5 py-0.5"
                >
                  <Link
                    v-for="child in item.children"
                    :key="child.code"
                    :href="child.href"
                    class="flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium rounded-md transition-all duration-150"
                    :class="isItemActive(child.href)
                      ? 'bg-accent text-white font-semibold shadow-xs'
                      : 'text-ink-600 hover:bg-surface-100 hover:text-ink-900'"
                    @click="mobileSidebar?.close()"
                  >
                    <component
                      :is="getIcon(child.icon)"
                      v-if="child.icon"
                      class="h-3.5 w-3.5 shrink-0"
                      :class="isItemActive(child.href) ? 'text-white' : 'text-ink-400'"
                    />
                    <span
                      v-else
                      class="w-1.5 h-1.5 rounded-full shrink-0"
                      :class="isItemActive(child.href) ? 'bg-white' : 'bg-ink-400'"
                    />
                    <span class="truncate">{{ child.label }}</span>
                  </Link>
                </div>
              </div>

              <!-- Single Item without Children -->
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
