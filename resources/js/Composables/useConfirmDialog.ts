// ponytail: Simple global confirmation state manager
import { ref } from 'vue'

interface ConfirmOptions {
  title: string
  description?: string
  confirmText?: string
  cancelText?: string
  variant?: 'default' | 'destructive'
  onConfirm: () => void
}

export const confirmState = ref<{
  open: boolean
  title: string
  description: string
  confirmText: string
  cancelText: string
  variant: 'default' | 'destructive'
  onConfirm: () => void
} | null>(null)

export function useConfirm() {
  const confirm = (options: ConfirmOptions) => {
    confirmState.value = {
      open: true,
      title: options.title,
      description: options.description ?? '',
      confirmText: options.confirmText ?? 'Confirm',
      cancelText: options.cancelText ?? 'Cancel',
      variant: options.variant ?? 'default',
      onConfirm: () => {
        options.onConfirm()
        close()
      }
    }
  }

  const close = () => {
    confirmState.value = null
  }

  return { confirm, close }
}
