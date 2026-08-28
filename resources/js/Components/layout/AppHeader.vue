<!-- ponytail: Simple header linking user dropdown and responsive actions -->
<script setup lang="ts">
import { inject, type Ref } from 'vue'
import { Menu } from 'lucide-vue-next'
import UserDropdown from './UserDropdown.vue'
import AppBreadcrumb from './AppBreadcrumb.vue'
import CompanySwitcher from './CompanySwitcher.vue'
import ThemeSwitcher from './ThemeSwitcher.vue'

const mobileSidebar = inject<{
  isOpen: Ref<boolean>
  toggle: () => void
  close: () => void
} | null>('mobileSidebar', null)
</script>

<template>
  <header class="h-16 border-b border-border bg-surface-0 flex items-center justify-between px-3 sm:px-6 shrink-0 z-10">
    <div class="flex items-center gap-2 sm:gap-4 min-w-0">
      <!-- Mobile hamburger toggle -->
      <button
        type="button"
        class="md:hidden inline-flex items-center justify-center p-2 rounded-md text-ink-600 hover:text-ink-900 hover:bg-surface-50 focus:outline-none cursor-pointer"
        aria-label="Open navigation menu"
        @click="mobileSidebar?.toggle()"
      >
        <Menu class="h-5 w-5" />
      </button>

      <!-- Mobile brand indicator -->
      <div class="block sm:hidden text-xs font-bold uppercase tracking-wider text-ink-900 truncate">
        NusaEvo
      </div>

      <div class="hidden sm:block truncate">
        <AppBreadcrumb />
      </div>
    </div>

    <div class="flex items-center gap-1.5 sm:gap-3 shrink-0">
      <CompanySwitcher />
      <ThemeSwitcher />
      <UserDropdown />
    </div>
  </header>
</template>
