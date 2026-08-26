<!-- ponytail: Simple global ConfirmDialog displaying active confirmation state ref.
     Styled with design-system tokens (DESIGN.md §2): dialog = 8px radius card,
     destructive = signal-danger, default = accent. -->
<script setup lang="ts">
import { confirmState, useConfirm } from '@/Composables/useConfirmDialog'
import Modal from '@/Components/Modal.vue'

const { close } = useConfirm()

const handleConfirm = () => {
  if (confirmState.value) {
    confirmState.value.onConfirm()
  }
}
</script>

<template>
  <Modal :show="!!confirmState?.open" max-width="lg" @close="close">
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="confirm-dialog-title"
      class="text-left"
    >
      <div class="p-6">
        <h3 id="confirm-dialog-title" class="text-base font-semibold text-ink-900">
          {{ confirmState?.title }}
        </h3>
        <p v-if="confirmState?.description" class="mt-2 text-sm text-ink-600">
          {{ confirmState?.description }}
        </p>
      </div>
      <div class="flex flex-row-reverse gap-2 border-t border-border bg-surface-50 px-6 py-3">
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-sm px-3 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          :class="
            confirmState?.variant === 'destructive'
              ? 'bg-signal-danger hover:bg-signal-danger/90'
              : 'bg-accent hover:bg-accent/90'
          "
          @click="handleConfirm"
        >
          {{ confirmState?.confirmText }}
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-sm border border-border bg-white px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          @click="close"
        >
          {{ confirmState?.cancelText }}
        </button>
      </div>
    </div>
  </Modal>
</template>
