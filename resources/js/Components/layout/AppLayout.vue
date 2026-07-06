<!-- ponytail: Full screen shell integrating sidebar, header, content slots, and global feedback overlays -->
<script setup lang="ts">
import AppSidebar from './AppSidebar.vue'
import AppHeader from './AppHeader.vue'
import AppContent from './AppContent.vue'
import Toast from '@/Components/feedback/Toast.vue'
import ConfirmDialog from '@/Components/modals/ConfirmDialog.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()
</script>

<template>
  <div class="flex h-screen bg-gray-50 overflow-hidden font-sans">
    <AppSidebar />
    <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
      <AppHeader />
      <AppContent>
        <slot />
      </AppContent>
    </div>
    
    <Toast />
    <ConfirmDialog />
  </div>
</template>
