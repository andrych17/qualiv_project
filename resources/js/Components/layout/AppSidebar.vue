<!-- ponytail: Sidebar from shared navMenus (never name page props `menus`) -->
<script setup lang="ts">
import { computed, type Component } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { HelpCircle } from 'lucide-vue-next'
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
  <aside class="w-64 border-r border-gray-200 bg-white flex flex-col h-screen sticky top-0">
    <div class="border-b border-gray-100 px-4 py-3 space-y-2">
      <div class="px-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
        NusaEvo ERP
      </div>
      <TenantSwitcher />
    </div>

    <nav class="flex-1 overflow-y-auto p-4 space-y-4">
      <div v-for="section in menuSections" :key="section.header" class="space-y-1">
        <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
          {{ section.header }}
        </p>
        <Link
          v-for="item in section.items"
          :key="item.code"
          :href="item.href"
          class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
          :class="isActive(item.href)
            ? 'bg-gray-100 text-gray-900'
            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
        >
          <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
          <span>{{ item.label }}</span>
        </Link>
      </div>
      <p v-if="menuSections.length === 0" class="px-3 py-2 text-xs text-gray-400">
        No menus assigned
      </p>
    </nav>
  </aside>
</template>
