<!-- ponytail: Full screen shell integrating sidebar, header, content slots, and global feedback overlays -->
<script setup lang="ts">
import { ref, provide } from 'vue'
import AppSidebar from './AppSidebar.vue'
import AppHeader from './AppHeader.vue'
import AppContent from './AppContent.vue'
import Toast from '@/Components/feedback/Toast.vue'
import ConfirmDialog from '@/Components/modals/ConfirmDialog.vue'
import AlertDialog from '@/Components/modals/AlertDialog.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

useFlashToast()

const mobileSidebarOpen = ref(false)
const toggleMobileSidebar = () => {
  mobileSidebarOpen.value = !mobileSidebarOpen.value
}
const closeMobileSidebar = () => {
  mobileSidebarOpen.value = false
}

provide('mobileSidebar', {
  isOpen: mobileSidebarOpen,
  toggle: toggleMobileSidebar,
  close: closeMobileSidebar,
})
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-surface-50 font-sans text-ink-900">
    <AppSidebar />
    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <AppHeader />
      <AppContent>
        <slot />
      </AppContent>
    </div>

    <Toast />
    <AlertDialog />
    <ConfirmDialog />
  </div>
</template>
