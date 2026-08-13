<!-- ponytail: Sidebar from shared navMenus (never name page props `menus`) -->
<script setup lang="ts">
import { computed, defineAsyncComponent, type Component } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { HelpCircle } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'

// Config > Menus lets admins type any Lucide icon name freely, so we can't hardcode
// a fixed allowlist. Glob each icon as its own chunk instead of `import * as icons
// from 'lucide-vue-next'` (which pulled the whole ~152kB gzip library into the main
// bundle) — only the icons actually used by a tenant's menus get fetched.
const iconModules = import.meta.glob<{ default: Component }>(
  '/node_modules/lucide-vue-next/dist/esm/icons/*.js',
)

const toKebabCase = (name: string) =>
  name
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
    .toLowerCase()

const iconCache = new Map<string, Component>()

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

  const cached = iconCache.get(name)
  if (cached) return cached

  const path = `/node_modules/lucide-vue-next/dist/esm/icons/${toKebabCase(name)}.js`
  const loader = iconModules[path]
  if (!loader) return HelpCircle

  const component = defineAsyncComponent({
    loader: () => loader().then((mod) => mod.default),
    errorComponent: HelpCircle,
  })
  iconCache.set(name, component)

  return component
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
