<!-- ponytail: CentralAdminLayout matching AppLayout structure with distinct Central Platform indicators -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import {
  LayoutDashboard,
  Building2,
  Layers,
  Receipt,
  LogOut,
  ShieldCheck,
  ChevronRight,
  ChevronDown,
  Home,
} from 'lucide-vue-next'
import Toast from '@/Components/feedback/Toast.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()

const page = usePage()
const isUserMenuOpen = ref(false)

const user = computed(() => {
  return (page.props.auth as any)?.user ?? { name: 'Admin', email: 'admin@nusaevo.com' }
})

const navItems = [
  { name: 'Dashboard', href: route('central.dashboard'), pattern: 'central.dashboard', icon: LayoutDashboard },
  { name: 'Tenants', href: route('central.tenants.index'), pattern: 'central.tenants.*', icon: Building2 },
  { name: 'Plans', href: route('central.plans.index'), pattern: 'central.plans.*', icon: Layers },
  { name: 'Invoices', href: route('central.invoices.index'), pattern: 'central.invoices.*', icon: Receipt },
]

const isActive = (pattern: string) => {
  return route().current(pattern)
}

const breadcrumbs = computed(() => {
  const path = page.url.split('?')[0]
  const segments = path.split('/').filter(Boolean)

  return segments.map((segment, index) => {
    const built = '/' + segments.slice(0, index + 1).join('/')
    const label = segment.replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    return {
      label: label === 'Central' ? 'Central Platform' : label,
      href: built === '/central' ? route('central.dashboard') : built,
      active: index === segments.length - 1,
    }
  })
})

const logout = () => {
  isUserMenuOpen.value = false
  router.post(route('central.logout'))
}
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-gray-50 font-sans">
    <!-- Left Sidebar (Aligned with AppSidebar) -->
    <aside class="w-64 border-r border-gray-200 bg-white flex flex-col h-screen sticky top-0 shrink-0">
      <!-- Sidebar Header with Central Platform Indicator -->
      <div class="border-b border-gray-100 px-4 py-3 space-y-2">
        <div class="flex items-center justify-between px-1">
          <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">NusaEvo ERP</span>
          <span class="inline-flex items-center rounded-md bg-purple-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-purple-700 ring-1 ring-inset ring-purple-700/20">
            Central
          </span>
        </div>

        <!-- Central Platform Badge Banner -->
        <div class="flex items-center gap-2.5 rounded-lg border border-purple-200 bg-purple-50/70 p-2.5">
          <div class="flex h-8 w-8 items-center justify-center rounded-md bg-purple-700 text-white shadow-xs">
            <ShieldCheck class="h-4 w-4" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold text-purple-950 leading-none truncate">Platform Admin</p>
            <p class="mt-1 text-[11px] font-medium text-purple-700 leading-none truncate">Global Management</p>
          </div>
        </div>
      </div>

      <!-- Sidebar Navigation Menu -->
      <nav class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="space-y-1">
          <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
            Platform Management
          </p>
          <Link
            v-for="item in navItems"
            :key="item.name"
            :href="item.href"
            class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
            :class="isActive(item.pattern)
              ? 'bg-purple-50 text-purple-900 font-semibold shadow-xs'
              : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
          >
            <component
              :is="item.icon"
              class="h-4 w-4 shrink-0"
              :class="isActive(item.pattern) ? 'text-purple-700' : 'text-gray-400'"
            />
            <span>{{ item.name }}</span>
          </Link>
        </div>
      </nav>
    </aside>

    <!-- Main Shell (Header + Content Slot) -->
    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <!-- Top Header (Aligned with AppHeader) -->
      <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-6 shrink-0">
        <!-- Breadcrumbs & Badge -->
        <div class="flex items-center gap-3">
          <nav class="flex items-center gap-2 text-sm text-gray-600">
            <Link
              :href="route('central.dashboard')"
              class="flex items-center text-gray-400 hover:text-gray-700 transition-colors"
            >
              <Home class="h-4 w-4" />
            </Link>

            <div v-for="crumb in breadcrumbs" :key="crumb.label + crumb.href" class="flex items-center gap-2">
              <ChevronRight class="h-4 w-4 text-gray-400" />
              <span v-if="crumb.active" class="font-medium text-gray-900">{{ crumb.label }}</span>
              <Link
                v-else
                :href="crumb.href"
                class="text-gray-600 hover:text-gray-900 transition-colors"
              >
                {{ crumb.label }}
              </Link>
            </div>
          </nav>
        </div>

        <!-- User Dropdown (Central Admin Guard) -->
        <div class="relative">
          <button
            type="button"
            class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-900 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-purple-600"
            :aria-expanded="isUserMenuOpen"
            @click="isUserMenuOpen = !isUserMenuOpen"
          >
            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-purple-700 font-semibold text-xs text-white shadow-xs">
              {{ user?.name ? user.name.charAt(0).toUpperCase() : 'A' }}
            </div>
            <div class="hidden text-left sm:block">
              <div class="flex items-center gap-1.5">
                <p class="text-xs font-semibold leading-none text-gray-900">{{ user?.name }}</p>
                <span class="rounded bg-purple-100 px-1 py-0.2 text-[9px] font-bold text-purple-700">CENTRAL</span>
              </div>
              <p class="mt-0.5 text-[11px] leading-none text-gray-500 truncate max-w-[140px]">{{ user?.email }}</p>
            </div>
            <ChevronDown class="h-4 w-4 text-gray-500 transition-transform" :class="{ 'rotate-180': isUserMenuOpen }" />
          </button>

          <div
            v-if="isUserMenuOpen"
            class="fixed inset-0 z-10"
            @click="isUserMenuOpen = false"
          />

          <div
            v-if="isUserMenuOpen"
            class="absolute right-0 z-20 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5"
          >
            <div class="border-b border-gray-100 px-4 py-3 bg-purple-50/40">
              <p class="text-[11px] font-semibold uppercase tracking-wider text-purple-800">Central Platform Admin</p>
              <p class="mt-0.5 text-sm font-semibold text-gray-900 truncate">{{ user?.name }}</p>
              <p class="text-xs text-gray-500 truncate">{{ user?.email }}</p>
            </div>

            <div class="py-1">
              <button
                type="button"
                class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-red-600 transition-colors hover:bg-red-50"
                @click="logout"
              >
                <LogOut class="h-4 w-4" />
                <span>Keluar (Log Out)</span>
              </button>
            </div>
          </div>
        </div>
      </header>

      <!-- Content Area (Aligned with AppContent) -->
      <main class="flex-1 overflow-y-auto p-6 bg-gray-50">
        <slot />
      </main>
    </div>

    <Toast />
  </div>
</template>
