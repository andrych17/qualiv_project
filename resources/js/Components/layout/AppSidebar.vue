<!-- ponytail: Minimal, clear sidebar styling with Lucide icons dynamic rendering -->
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import * as icons from 'lucide-vue-next'

const page = usePage()

const menuItems = [
  { label: 'Dashboard', icon: 'LayoutDashboard', href: route('dashboard') },
  { label: 'CRM', icon: 'Users', href: '#' },
  { label: 'Schedule', icon: 'CalendarDays', href: '#' },
  { label: 'CMS', icon: 'FileText', href: '#' },
  { label: 'Legal', icon: 'Scale', href: '#' },
  { label: 'HSE', icon: 'ShieldCheck', href: '#' },
  { label: 'Project', icon: 'KanbanSquare', href: '#' },
  { label: 'Inventory', icon: 'Boxes', href: route('inventory.items.index') },
  { label: 'Sales', icon: 'ShoppingCart', href: '#' },
  { label: 'Procurement', icon: 'PackageSearch', href: '#' },
  { label: 'HCM', icon: 'UserRoundCog', href: '#' },
  { label: 'Payroll', icon: 'WalletCards', href: '#' },
  { label: 'Asset', icon: 'Archive', href: '#' },
  { label: 'Accounting', icon: 'Calculator', href: '#' },
  { label: 'Workflow', icon: 'Workflow', href: '#' },
  { label: 'Notifications', icon: 'Bell', href: '#' },
  { label: 'Delivery', icon: 'Truck', href: '#' },
]

const getIcon = (name: string) => {
  return (icons as Record<string, any>)[name] || icons.HelpCircle
}

const isActive = (href: string) => {
  if (href === '#') return false
  return page.url === new URL(href).pathname
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
        :key="item.label"
        :href="item.href"
        class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
        :class="isActive(item.href) 
          ? 'bg-gray-100 text-gray-900' 
          : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
      >
        <component :is="getIcon(item.icon)" class="h-4 w-4 shrink-0" />
        <span>{{ item.label }}</span>
      </Link>
    </nav>
  </aside>
</template>
