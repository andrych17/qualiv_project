<!-- ponytail: Simple header linking user dropdown and responsive actions -->
<script setup lang="ts">
import UserDropdown from './UserDropdown.vue'
import AppBreadcrumb from './AppBreadcrumb.vue'
import CompanySwitcher from './CompanySwitcher.vue'
import ThemeSwitcher from './ThemeSwitcher.vue'
</script>

<template>
  <header class="h-16 border-b border-border bg-surface-0 flex items-center justify-between px-6">
    <div class="flex items-center gap-4">
      <AppBreadcrumb />
    </div>

    <div class="flex items-center gap-3">
      <CompanySwitcher />
      <ThemeSwitcher />
      <UserDropdown />
    </div>
  </header>
</template>
