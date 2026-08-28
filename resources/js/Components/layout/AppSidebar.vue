<!-- ponytail: Sidebar from shared navMenus (never name page props `menus`) -->
<script setup lang="ts">
import { computed, inject, watch, type Component, type Ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { HelpCircle, X } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'

// Config > Menus allows dynamic icon names from Lucide. Direct lookup from
// lucide-vue-next avoids broken dynamic import paths in production builds.
import * as LucideIcons from 'lucide-vue-next'

type MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  header: string | null
}

type MenuSection = {
  header: string
  items: MenuItem[]
}

const page = usePage()

const mobileSidebar = inject<{
  isOpen: Ref<boolean>
  toggle: () => void
  close: () => void
} | null>('mobileSidebar', null)

watch(
  () => page.url,
  () => {
    mobileSidebar?.close()
  },
)

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

const isActive = (href: string) => {
  if (!href || href === '#') return false
  try {
    const path = href.startsWith('http') ? new URL(href).pathname : href
    return page.url === path || page.url.startsWith(path + '/')
  } catch {
    return false
  }
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
        <Link
          v-for="item in section.items"
          :key="item.code"
          :href="item.href"
          class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
          :class="isActive(item.href)
            ? 'bg-surface-50 text-accent font-semibold shadow-2xs'
            : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
        >
          <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
          <span>{{ item.label }}</span>
        </Link>
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
            <Link
              v-for="item in section.items"
              :key="item.code"
              :href="item.href"
              class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
              :class="isActive(item.href)
                ? 'bg-surface-50 text-accent font-semibold shadow-2xs'
                : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              @click="mobileSidebar?.close()"
            >
              <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
              <span>{{ item.label }}</span>
            </Link>
          </div>
          <p v-if="menuSections.length === 0" class="px-3 py-2 text-xs text-ink-600">
            No menus assigned
          </p>
        </nav>
      </aside>
    </div>
  </Teleport>
</template>
