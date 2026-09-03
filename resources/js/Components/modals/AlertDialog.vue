<!-- Centered global AlertDialog component for displaying Success, Error, Warning, and Info dialogs -->
<script setup lang="ts">
import { computed } from 'vue'
import { alertState, useAlert } from '@/Composables/useAlertDialog'
import Modal from '@/Components/Modal.vue'
import { CheckCircle2, AlertCircle, AlertTriangle, Info } from 'lucide-vue-next'

const { closeAlert } = useAlert()

const typeConfig = computed(() => {
  const type = alertState.value?.type ?? 'info'
  switch (type) {
    case 'success':
      return {
        icon: CheckCircle2,
        iconBgClass: 'bg-emerald-500/10 text-emerald-500 ring-8 ring-emerald-500/10',
        buttonClass: 'bg-accent hover:bg-accent/90 text-accent-text focus-visible:outline-accent',
      }
    case 'error':
      return {
        icon: AlertCircle,
        iconBgClass: 'bg-signal-danger/10 text-signal-danger ring-8 ring-signal-danger/10',
        buttonClass: 'bg-signal-danger hover:bg-signal-danger/90 text-white focus-visible:outline-signal-danger',
      }
    case 'warning':
      return {
        icon: AlertTriangle,
        iconBgClass: 'bg-signal-warning/10 text-signal-warning ring-8 ring-signal-warning/10',
        buttonClass: 'bg-signal-warning hover:bg-signal-warning/90 text-ink-900 focus-visible:outline-signal-warning font-semibold',
      }
    case 'info':
    default:
      return {
        icon: Info,
        iconBgClass: 'bg-signal-info/10 text-signal-info ring-8 ring-signal-info/10',
        buttonClass: 'bg-accent hover:bg-accent/90 text-accent-text focus-visible:outline-accent',
      }
  }
})
</script>

<template>
  <Modal :show="!!alertState?.open" max-width="md" @close="closeAlert">
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="alert-dialog-title"
      aria-describedby="alert-dialog-description"
      class="p-6 text-center bg-surface-0 text-ink-900 rounded-lg overflow-hidden"
    >
      <!-- Centered Icon Circle -->
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full" :class="typeConfig.iconBgClass">
        <component :is="typeConfig.icon" class="h-7 w-7" aria-hidden="true" />
      </div>

      <!-- Title & Description -->
      <div class="mt-4">
        <h3 id="alert-dialog-title" class="text-base font-semibold text-ink-900">
          {{ alertState?.title }}
        </h3>
        <p id="alert-dialog-description" class="mt-2 text-sm text-ink-600 leading-relaxed break-words whitespace-pre-line">
          {{ alertState?.message }}
        </p>
      </div>

      <!-- Centered Action Button -->
      <div class="mt-6">
        <button
          type="button"
          autofocus
          class="inline-flex w-full items-center justify-center rounded-md px-4 py-2.5 text-sm font-semibold shadow-xs transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 cursor-pointer"
          :class="typeConfig.buttonClass"
          @click="closeAlert"
        >
          {{ alertState?.buttonText }}
        </button>
      </div>
    </div>
  </Modal>
</template>
