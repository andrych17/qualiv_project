<!-- ponytail: minimal shell for the Shop Floor / Batch execution pages (MES_SPECS.md §3G/§3I §5 — "not the standard AppLayout admin chrome") -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import Toast from '@/Components/feedback/Toast.vue'
import ConfirmDialog from '@/Components/modals/ConfirmDialog.vue'
import AlertDialog from '@/Components/modals/AlertDialog.vue'
import { useFlashToast } from '@/Composables/useFlashToast'

defineProps<{
  exitHref: string
  title: string
}>()

useFlashToast()
</script>

<template>
  <div class="flex h-screen flex-col overflow-hidden bg-surface-50 font-sans text-ink-900">
    <header class="flex flex-none items-center gap-3 border-b border-border bg-surface-0 px-4 py-3">
      <Link
        :href="exitHref"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-ink-600 hover:bg-surface-50 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        <ArrowLeft class="h-4 w-4" /> Exit
      </Link>
      <h1 class="text-base font-semibold text-ink-900">{{ title }}</h1>
    </header>

    <main class="flex-1 overflow-y-auto p-4 sm:p-6">
      <slot />
    </main>

    <Toast />
    <AlertDialog />
    <ConfirmDialog />
  </div>
</template>
