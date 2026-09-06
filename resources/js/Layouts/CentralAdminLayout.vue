<!-- ponytail: CentralAdminLayout matching AppLayout structure with distinct Central Platform indicators and responsive mobile drawer -->
<script setup lang="ts">
import { ref, computed, provide, onUnmounted } from 'vue'
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
  Menu as MenuIcon,
  X,
} from 'lucide-vue-next'
import Toast from '@/Components/feedback/Toast.vue'
import AlertDialog from '@/Components/modals/AlertDialog.vue'
import ConfirmDialog from '@/Components/modals/ConfirmDialog.vue'
import ThemeSwitcher from '@/Components/layout/ThemeSwitcher.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()

const page = usePage()
const isUserMenuOpen = ref(false)
const isMobileSidebarOpen = ref(false)

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

const removeNavListener = router.on('navigate', () => {
  isMobileSidebarOpen.value = false
})

onUnmounted(() => {
  removeNavListener()
})

const logout = () => {
  isUserMenuOpen.value = false
  router.post(route('central.logout'))
}
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-surface-50 text-ink-900 font-sans">
    <!-- Desktop Left Sidebar -->
    <aside class="hidden md:flex w-64 border-r border-border bg-surface-0 flex-col h-screen sticky top-0 shrink-0">
      <!-- Sidebar Header with Central Platform Indicator -->
      <div class="border-b border-border px-4 py-3 space-y-2">
        <div class="flex items-center justify-between px-1">
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-600">Qualiv Project</span>
          <span class="inline-flex items-center rounded-md bg-accent/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-accent ring-1 ring-inset ring-accent/20">
            Central
          </span>
        </div>

        <!-- Central Platform Badge Banner -->
        <div class="flex items-center gap-2.5 rounded-lg border border-accent/20 bg-accent/5 p-2.5">
          <div class="flex h-8 w-8 items-center justify-center rounded-md bg-accent text-white shadow-xs">
            <ShieldCheck class="h-4 w-4" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-xs font-bold text-ink-900 leading-none truncate">Platform Admin</p>
            <p class="mt-1 text-[11px] font-medium text-accent leading-none truncate">Global Management</p>
          </div>
        </div>
      </div>

      <!-- Sidebar Navigation Menu -->
      <nav class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="space-y-1">
          <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-ink-600">
            Platform Management
          </p>
          <Link
            v-for="item in navItems"
            :key="item.name"
            :href="item.href"
            class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-md transition-colors"
            :class="isActive(item.pattern)
              ? 'bg-accent/10 text-accent font-semibold shadow-xs'
              : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
          >
            <component
              :is="item.icon"
              class="h-4 w-4 shrink-0"
              :class="isActive(item.pattern) ? 'text-accent' : 'text-ink-600'"
            />
            <span>{{ item.name }}</span>
          </Link>
        </div>
      </nav>
    </aside>

    <!-- Mobile Drawer Teleport -->
    <Teleport to="body">
      <div
        v-if="isMobileSidebarOpen"
        class="fixed inset-0 z-50 flex md:hidden"
      >
        <div
          class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
          @click="isMobileSidebarOpen = false"
        />

        <aside class="relative flex w-72 max-w-[85vw] flex-col bg-surface-0 border-r border-border shadow-2xl z-10">
          <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <div class="flex items-center gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-ink-600">Qualiv Project</span>
              <span class="inline-flex items-center rounded-md bg-accent/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-accent">
                Central
              </span>
            </div>
            <button
              type="button"
              class="p-1 rounded-md text-ink-600 hover:bg-surface-50 hover:text-ink-900 cursor-pointer"
              @click="isMobileSidebarOpen = false"
            >
              <X class="h-5 w-5" />
            </button>
          </div>

          <nav class="flex-1 overflow-y-auto p-4 space-y-2">
            <Link
              v-for="item in navItems"
              :key="item.name"
              :href="item.href"
              class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-md transition-colors"
              :class="isActive(item.pattern)
                ? 'bg-accent/10 text-accent font-semibold shadow-xs'
                : 'text-ink-600 hover:bg-surface-50 hover:text-ink-900'"
              @click="isMobileSidebarOpen = false"
            >
              <component
                :is="item.icon"
                class="h-4 w-4 shrink-0"
                :class="isActive(item.pattern) ? 'text-accent' : 'text-ink-600'"
              />
              <span>{{ item.name }}</span>
            </Link>
          </nav>
        </aside>
      </div>
    </Teleport>

    <!-- Main Shell (Header + Content Slot) -->
    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <!-- Top Header (Aligned with AppHeader) -->
      <header class="h-16 border-b border-border bg-surface-0 flex items-center justify-between px-3 sm:px-6 shrink-0 relative z-50">
        <!-- Breadcrumbs & Badge -->
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="md:hidden inline-flex items-center justify-center p-2 rounded-lg text-ink-600 hover:text-ink-900 hover:bg-surface-50 cursor-pointer focus:outline-none"
            aria-label="Open sidebar"
            @click="isMobileSidebarOpen = true"
          >
            <MenuIcon class="h-5 w-5" />
          </button>

          <nav class="flex items-center gap-2 text-sm text-ink-600">
            <Link
              :href="route('central.dashboard')"
              class="flex items-center text-ink-600 hover:text-ink-900 transition-colors"
            >
              <Home class="h-4 w-4" />
            </Link>

            <div v-for="crumb in breadcrumbs" :key="crumb.label + crumb.href" class="hidden sm:flex items-center gap-2">
              <ChevronRight class="h-4 w-4 text-ink-600" />
              <span v-if="crumb.active" class="font-medium text-ink-900">{{ crumb.label }}</span>
              <Link
                v-else
                :href="crumb.href"
                class="text-ink-600 hover:text-ink-900 transition-colors"
              >
                {{ crumb.label }}
              </Link>
            </div>
          </nav>
        </div>

        <div class="flex items-center gap-3">
          <ThemeSwitcher />

          <!-- User Dropdown (Central Admin Guard) -->
          <div class="relative z-50">
            <button
              type="button"
              class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm font-medium text-ink-900 transition-colors hover:bg-surface-50 focus:outline-none cursor-pointer"
              :aria-expanded="isUserMenuOpen"
              @click="isUserMenuOpen = !isUserMenuOpen"
            >
              <div class="flex h-8 w-8 items-center justify-center rounded-full bg-accent font-semibold text-xs text-white shadow-xs">
                {{ user?.name ? user.name.charAt(0).toUpperCase() : 'A' }}
              </div>
              <div class="hidden text-left sm:block">
                <div class="flex items-center gap-1.5">
                  <p class="text-xs font-semibold leading-none text-ink-900">{{ user?.name }}</p>
                  <span class="rounded bg-accent/10 px-1 py-0.2 text-[9px] font-bold text-accent">CENTRAL</span>
                </div>
                <p class="mt-0.5 text-[11px] leading-none text-ink-600 truncate max-w-[140px]">{{ user?.email }}</p>
              </div>
              <ChevronDown class="h-4 w-4 text-ink-600 transition-transform" :class="{ 'rotate-180': isUserMenuOpen }" />
            </button>

            <div
              v-if="isUserMenuOpen"
              class="fixed inset-0 z-50"
              @click="isUserMenuOpen = false"
            />

            <div
              v-if="isUserMenuOpen"
              class="absolute right-0 z-50 mt-2 w-56 max-w-[calc(100vw-1.5rem)] rounded-md border border-border bg-surface-0 py-1 shadow-2xl ring-1 ring-black/10"
            >
              <div class="border-b border-border px-4 py-3 bg-surface-50">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-accent">Central Platform Admin</p>
                <p class="mt-0.5 text-sm font-semibold text-ink-900 truncate">{{ user?.name }}</p>
                <p class="text-xs text-ink-600 truncate">{{ user?.email }}</p>
              </div>

              <div class="py-1">
                <button
                  type="button"
                  class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-signal-danger transition-colors hover:bg-surface-50 cursor-pointer"
                  @click="logout"
                >
                  <LogOut class="h-4 w-4" />
                  <span>Keluar (Log Out)</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- Content Area (Aligned with AppContent) -->
      <main class="flex-1 overflow-y-auto p-3 sm:p-4 md:p-6 bg-surface-50 text-ink-900 min-w-0 max-w-full relative z-0 isolate">
        <slot />
      </main>
    </div>

    <Toast />
    <AlertDialog />
    <ConfirmDialog />
  </div>
</template>
