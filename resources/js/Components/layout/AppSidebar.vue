<!-- ponytail: Sidebar driven by SYSCONFIG.config_menus via Inertia shared menus -->
<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import * as icons from 'lucide-vue-next'

type MenuItem = {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
}

const page = usePage()

const menuItems = computed(() => (page.props.menus as MenuItem[] | undefined) ?? [])

const getIcon = (name: string | null) => {
  if (!name) return icons.HelpCircle
  return (icons as Record<string, unknown>)[name] as typeof icons.HelpCircle || icons.HelpCircle
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
    <div class="h-16 flex items-center px-6 border-b border-gray-100">
      <span class="text-xl font-bold tracking-tight text-gray-900">NusaEvo ERP</span>
    </div>
    
    <nav class="flex-1 overflow-y-auto p-4 space-y-1">
      <Link
        v-for="item in menuItems"
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
      <p v-if="menuItems.length === 0" class="px-3 py-2 text-xs text-gray-400">
        No menus assigned
      </p>
    </nav>
  </aside>
</template>
