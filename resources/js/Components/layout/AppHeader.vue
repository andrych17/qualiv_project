<!-- ponytail: Simple header linking user dropdown and responsive actions -->
<script setup lang="ts">
import UserDropdown from './UserDropdown.vue'
import AppBreadcrumb from './AppBreadcrumb.vue'
</script>

<template>
  <header class="h-16 border-b border-gray-200 bg-white flex items-center justify-between px-6">
    <div class="flex items-center gap-4">
      <AppBreadcrumb />
    </div>
    
    <div class="flex items-center gap-4">
      <UserDropdown />
    </div>
  </header>
</template>
