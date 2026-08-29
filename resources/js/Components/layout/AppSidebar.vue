<!-- ponytail: Sidebar from shared navMenus with nested submenu support -->
<script setup lang="ts">
import { computed, inject, ref, watch, type Component, type Ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronDown, ChevronRight, HelpCircle, X } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'

// Config > Menus allows dynamic icon names from Lucide. Direct lookup from
// lucide-vue-next avoids broken dynamic import paths in production builds.
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

const isItemActive = (href: string) => {
  if (!href || href === '#') return false
  try {
    const path = href.startsWith('http') ? new URL(href).pathname : href
    return page.url === path || page.url.startsWith(path + '/')
  } catch {
    return false
  }
}

const isParentActive = (item: MenuItem) => {
  if (isItemActive(item.href)) return true
  if (item.children && item.children.length > 0) {
    return item.children.some((c) => isItemActive(c.href))
  }
  return false
}

const toggleSubmenu = (code: string) => {
  openMenus.value[code] = !openMenus.value[code]
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

// Auto-expand parents whose children match current URL
watch(
  () => page.url,
  () => {
    mobileSidebar?.close()
    const items = (page.props.navMenus as MenuItem[] | undefined) ?? []
    for (const item of items) {
      if (item.children && item.children.length > 0 && isParentActive(item)) {
        openMenus.value[item.code] = true
      }
    }
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
  <aside class="hidden md:flex flex-col w-64 border-r border-border bg-surface-0 h-screen sticky top-0 shrink-0">
    <div class="border-b border-border px-4 py-3 space-y-2">
      <div class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-600">
        NusaEvo ERP
      </div>
      <TenantSwitcher />
    </div>

    <nav class="flex-1 overflow-y-auto p-4 space-y-4">
      <div v-for="section in menuSections" :key="section.header" class="space-y-1">
        <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-ink-600">
          {{ section.header }}
        </p>

        <div v-for="item in section.items" :key="item.code" class="space-y-1">
          <!-- Accordion Menu with Children -->
          <div v-if="item.children && item.children.length > 0">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors cursor-pointer"
              :class="isParentActive(item)
                ? 'text-accent font-semibold bg-accent/5'
                : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              @click="toggleSubmenu(item.code)"
            >
              <div class="flex items-center gap-3 truncate">
                <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ item.label }}</span>
              </div>
              <ChevronDown
                v-if="openMenus[item.code]"
                class="h-4 w-4 shrink-0 transition-transform text-ink-400"
              />
              <ChevronRight
                v-else
                class="h-4 w-4 shrink-0 transition-transform text-ink-400"
              />
            </button>

            <!-- Nested Submenu Children -->
            <div
              v-show="openMenus[item.code]"
              class="mt-1 ml-4 pl-3 border-l border-border space-y-1"
            >
              <Link
                v-for="child in item.children"
                :key="child.code"
                :href="child.href"
                class="flex items-center gap-2.5 px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors"
                :class="isItemActive(child.href)
                  ? 'bg-surface-100 text-accent font-semibold'
                  : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              >
                <component :is="getIcon(child.icon)" v-if="child.icon" class="h-3.5 w-3.5 shrink-0 opacity-80" />
                <span>{{ child.label }}</span>
              </Link>
            </div>
          </div>

          <!-- Single Item without Children -->
          <Link
            v-else
            :href="item.href"
            class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
            :class="isItemActive(item.href)
              ? 'bg-surface-50 text-accent font-semibold shadow-2xs'
              : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
          >
            <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
            <span>{{ item.label }}</span>
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
        class="relative flex flex-col w-72 max-w-[85vw] h-full bg-surface-0 border-r border-border shadow-2xl z-10"
      >
        <div class="border-b border-border px-4 py-3 flex items-center justify-between">
          <div class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-600">
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

        <nav class="flex-1 overflow-y-auto p-4 space-y-4">
          <div v-for="section in menuSections" :key="section.header" class="space-y-1">
            <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-ink-600">
              {{ section.header }}
            </p>

            <div v-for="item in section.items" :key="item.code" class="space-y-1">
              <!-- Accordion Menu with Children -->
              <div v-if="item.children && item.children.length > 0">
                <button
                  type="button"
                  class="flex w-full items-center justify-between gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors cursor-pointer"
                  :class="isParentActive(item)
                    ? 'text-accent font-semibold bg-accent/5'
                    : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
                  @click="toggleSubmenu(item.code)"
                >
                  <div class="flex items-center gap-3 truncate">
                    <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ item.label }}</span>
                  </div>
                  <ChevronDown
                    v-if="openMenus[item.code]"
                    class="h-4 w-4 shrink-0 transition-transform text-ink-400"
                  />
                  <ChevronRight
                    v-else
                    class="h-4 w-4 shrink-0 transition-transform text-ink-400"
                  />
                </button>

                <!-- Nested Submenu Children -->
                <div
                  v-show="openMenus[item.code]"
                  class="mt-1 ml-4 pl-3 border-l border-border space-y-1"
                >
                  <Link
                    v-for="child in item.children"
                    :key="child.code"
                    :href="child.href"
                    class="flex items-center gap-2.5 px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors"
                    :class="isItemActive(child.href)
                      ? 'bg-surface-100 text-accent font-semibold'
                      : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
                    @click="mobileSidebar?.close()"
                  >
                    <component :is="getIcon(child.icon)" v-if="child.icon" class="h-3.5 w-3.5 shrink-0 opacity-80" />
                    <span>{{ child.label }}</span>
                  </Link>
                </div>
              </div>

              <!-- Single Item without Children -->
              <Link
                v-else
                :href="item.href"
                class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
                :class="isItemActive(item.href)
                  ? 'bg-surface-50 text-accent font-semibold shadow-2xs'
                  : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
                @click="mobileSidebar?.close()"
              >
                <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
                <span>{{ item.label }}</span>
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
