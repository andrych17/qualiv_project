<!-- ponytail: Sidebar shell — brand, tenant switcher, collapse toggle and
     the mobile drawer chrome. The menu tree itself lives in SidebarNav.vue so desktop and
     mobile render the same markup instead of two copies that drift apart. -->
<script setup lang="ts">
import { inject, type Ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { X, PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next'
import TenantSwitcher from './TenantSwitcher.vue'
import SidebarNav from './SidebarNav.vue'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import { isCollapsed } from '@/State/sidebarNav'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const mobileSidebar = inject<{
  isOpen: Ref<boolean>
  toggle: () => void
  close: () => void
} | null>('mobileSidebar', null)

const toggleCollapse = () => {
  isCollapsed.value = !isCollapsed.value
}
</script>

<template>
  <!-- Desktop Collapsible Sidebar -->
  <aside
    class="hidden md:flex flex-col border-r border-border bg-surface-0 h-full min-h-0 shrink-0 select-none transition-all duration-200 ease-in-out relative z-30"
    :class="isCollapsed ? 'w-[72px]' : 'w-64'"
  >
    <!-- Header: Expanded -->
    <div v-if="!isCollapsed" class="border-b border-border p-3.5 space-y-2.5">
      <div class="flex items-center justify-between">
        <Link
          :href="route('dashboard')"
          class="inline-flex items-center focus:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-lg transition-opacity hover:opacity-80 cursor-pointer"
          title="Dashboard"
        >
          <ApplicationLogo size="sm" />
        </Link>

        <button
          type="button"
          class="p-1.5 rounded-lg text-ink-500 hover:text-ink-900 hover:bg-surface-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent transition-colors cursor-pointer"
          :title="t('nav.collapse_sidebar')"
          :aria-label="t('nav.collapse_sidebar')"
          @click="toggleCollapse"
        >
          <PanelLeftClose class="h-4 w-4" />
        </button>
      </div>

      <TenantSwitcher />
    </div>

    <!-- Header: Collapsed -->
    <div v-else class="border-b border-border p-2.5 flex flex-col items-center gap-2">
      <Link
        :href="route('dashboard')"
        class="p-1 inline-flex items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-lg transition-opacity hover:opacity-80 cursor-pointer"
        title="Dashboard"
      >
        <ApplicationLogo size="xs" icon-only />
      </Link>

      <button
        type="button"
        class="p-2 rounded-lg text-ink-500 hover:text-ink-900 hover:bg-surface-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent transition-colors cursor-pointer"
        :title="t('nav.expand_sidebar')"
        :aria-label="t('nav.expand_sidebar')"
        @click="toggleCollapse"
      >
        <PanelLeftOpen class="h-5 w-5 text-accent" />
      </button>
    </div>

    <SidebarNav :variant="isCollapsed ? 'rail' : 'tree'" :persist-scroll="!isCollapsed" />
  </aside>

  <!-- Mobile Off-Canvas Drawer -->
  <Teleport to="body">
    <div v-if="mobileSidebar?.isOpen.value" class="fixed inset-0 z-50 md:hidden flex">
      <div
        class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
        @click="mobileSidebar?.close()"
      />

      <aside
        class="relative flex h-full min-h-0 w-72 max-w-[85vw] flex-col bg-surface-0 border-r border-border shadow-2xl z-10 select-none"
      >
        <div class="border-b border-border px-4 py-3 flex items-center justify-between">
          <Link
            :href="route('dashboard')"
            class="inline-flex items-center focus:outline-none focus-visible:ring-2 focus-visible:ring-accent rounded-lg transition-opacity hover:opacity-80 cursor-pointer"
            title="Dashboard"
            @click="mobileSidebar?.close()"
          >
            <ApplicationLogo size="sm" />
          </Link>
          <button
            type="button"
            class="p-2 rounded-md text-ink-600 hover:text-ink-900 hover:bg-surface-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent cursor-pointer"
            :aria-label="t('nav.close_menu')"
            @click="mobileSidebar?.close()"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="border-b border-border px-4 py-2.5">
          <TenantSwitcher />
        </div>

        <SidebarNav variant="tree" touch @navigate="mobileSidebar?.close()" />
      </aside>
    </div>
  </Teleport>
</template>
