<!-- ponytail: Simple global ConfirmDialog displaying active confirmation state ref.
     Styled with design-system tokens (DESIGN.md §2): dialog = 8px radius card,
     destructive = signal-danger, default = accent. -->
<script setup lang="ts">
import { confirmState, useConfirm } from '@/Composables/useConfirmDialog'
import Modal from '@/Components/Modal.vue'
import { useI18n } from '@/Composables/useI18n'

const { close } = useConfirm()
const { t } = useI18n()

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
      class="text-left bg-surface-0 text-ink-900 rounded-lg overflow-hidden"
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
          class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-semibold shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
          :class="
            confirmState?.variant === 'destructive'
              ? 'bg-signal-danger hover:bg-signal-danger/90 text-white'
              : 'bg-accent hover:bg-accent/90 text-accent-text'
          "
          @click="handleConfirm"
        >
          {{ confirmState?.confirmText ? confirmState.confirmText : t('common.confirm') }}
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-md border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
          @click="close"
        >
          {{ confirmState?.cancelText ? confirmState.cancelText : t('common.cancel') }}
        </button>
      </div>
    </div>
  </Modal>
</template>
