<!-- ponytail: Left-sidebar shell for platform-admin (central_admin guard) -->
<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { LayoutDashboard, Building2, Layers, Receipt, LogOut, Shield } from 'lucide-vue-next'
import Toast from '@/Components/feedback/Toast.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()

const page = usePage()

const navItems = [
  { name: 'Dashboard', href: route('central.dashboard'), pattern: 'central.dashboard', icon: LayoutDashboard },
  { name: 'Tenants', href: route('central.tenants.index'), pattern: 'central.tenants.*', icon: Building2 },
  { name: 'Plans', href: route('central.plans.index'), pattern: 'central.plans.*', icon: Layers },
  { name: 'Invoices', href: route('central.invoices.index'), pattern: 'central.invoices.*', icon: Receipt },
]

const isActive = (pattern: string) => {
  return route().current(pattern)
}

const logout = () => router.post(route('central.logout'))
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-gray-50 font-sans">
    <!-- Left Sidebar -->
    <aside class="w-64 border-r border-gray-200 bg-white flex flex-col h-screen sticky top-0 shrink-0">
      <div class="border-b border-gray-100 px-5 py-4 flex items-center gap-3">
        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-900 text-white shadow-sm">
          <Shield class="h-5 w-5" />
        </div>
        <div>
          <div class="font-serif text-base font-semibold text-gray-900 leading-none">Nusaevo Central</div>
          <span class="text-[11px] font-medium text-gray-500">Platform Admin</span>
        </div>
      </div>

      <nav class="flex-1 overflow-y-auto p-4 space-y-1">
        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
          Management
        </p>
        <Link
          v-for="item in navItems"
          :key="item.name"
          :href="item.href"
          class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
          :class="isActive(item.pattern)
            ? 'bg-gray-100 text-gray-900 font-semibold shadow-xs'
            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
        >
          <component :is="item.icon" class="h-4 w-4 shrink-0" :class="isActive(item.pattern) ? 'text-gray-900' : 'text-gray-400'" />
          <span>{{ item.name }}</span>
        </Link>
      </nav>

      <!-- User footer & Logout -->
      <div class="border-t border-gray-100 p-4 bg-gray-50/50">
        <div class="flex items-center justify-between">
          <div class="min-w-0 flex-1 pr-2">
            <p class="truncate text-xs font-semibold text-gray-800">
              {{ (page.props.auth as any)?.user?.name ?? 'Central Admin' }}
            </p>
            <p class="truncate text-[11px] text-gray-500">
              {{ (page.props.auth as any)?.user?.email ?? 'admin@nusaevo.com' }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-md p-1.5 text-gray-500 hover:bg-gray-200/70 hover:text-gray-900 transition-colors"
            title="Log out"
            @click="logout"
          >
            <LogOut class="h-4 w-4" />
          </button>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="flex min-w-0 flex-1 flex-col overflow-y-auto">
      <main class="mx-auto w-full max-w-6xl px-6 py-8">
        <slot />
      </main>
    </div>

    <Toast />
  </div>
</template>
