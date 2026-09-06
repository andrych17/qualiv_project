<!-- ponytail: Simple header linking user dropdown and responsive actions -->
<script setup lang="ts">
import { inject, type Ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Menu, Search } from 'lucide-vue-next'
import UserDropdown from './UserDropdown.vue'
import AppBreadcrumb from './AppBreadcrumb.vue'
import CompanySwitcher from './CompanySwitcher.vue'
import ThemeSwitcher from './ThemeSwitcher.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import { openMenuSearch } from '@/Composables/useMenuSearch'

const mobileSidebar = inject<{
  isOpen: Ref<boolean>
  toggle: () => void
  close: () => void
} | null>('mobileSidebar', null)
</script>

<template>
  <header class="h-16 border-b border-border bg-surface-0 flex items-center justify-between px-3 sm:px-6 shrink-0 relative z-50">
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
      <Link
        :href="route('dashboard')"
        class="block sm:hidden text-xs font-bold uppercase tracking-wider text-ink-900 hover:text-accent transition-colors truncate"
      >
        Nusaevo ERP
      </Link>

      <div class="hidden sm:block truncate">
        <AppBreadcrumb />
      </div>
    </div>

    <!-- Search Menu Trigger Button -->
    <div class="flex items-center mx-2 sm:mx-4">
      <button
        type="button"
        class="hidden sm:flex items-center justify-between gap-3 px-3 py-1.5 h-9 rounded-lg border border-border bg-surface-50 hover:bg-surface-0 hover:border-accent/40 text-ink-600 hover:text-ink-900 transition-all duration-150 shadow-2xs cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent min-w-[200px] lg:min-w-[260px]"
        title="Cari Menu (Ctrl + Space)"
        aria-label="Cari menu"
        @click="openMenuSearch"
      >
        <div class="flex items-center gap-2 text-xs truncate">
          <Search class="h-4 w-4 text-ink-500 shrink-0" />
          <span class="truncate text-ink-600">Cari menu...</span>
        </div>
        <div class="flex items-center gap-1 shrink-0">
          <kbd class="inline-flex items-center rounded border border-border bg-surface-0 px-1.5 py-0.5 text-[10px] font-medium font-mono text-ink-500 shadow-2xs">
            Ctrl Space
          </kbd>
        </div>
      </button>

      <!-- Mobile Search Icon Button -->
      <button
        type="button"
        class="sm:hidden inline-flex items-center justify-center h-9 w-9 rounded-lg border border-border bg-surface-0 text-ink-600 hover:text-ink-900 hover:bg-surface-50 transition-colors cursor-pointer"
        title="Cari Menu (Ctrl + Space)"
        aria-label="Cari menu"
        @click="openMenuSearch"
      >
        <Search class="h-4 w-4" />
      </button>
    </div>

    <div class="flex items-center gap-1.5 sm:gap-3 shrink-0">
      <CompanySwitcher />
      <LanguageSwitcher />
      <ThemeSwitcher />
      <UserDropdown />
    </div>
  </header>
</template>
