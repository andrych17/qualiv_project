// Global alert dialog state manager and flash session watcher
import { ref, watch, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

export type AlertType = 'success' | 'error' | 'warning' | 'info'

export interface AlertDialogOptions {
  title?: string
  message: string
  type?: AlertType
  buttonText?: string
  onClose?: () => void
}

export const alertState = ref<{
  open: boolean
  title: string
  message: string
  type: AlertType
  buttonText: string
  onClose?: () => void
} | null>(null)

export function useAlert() {
  const showAlert = (options: AlertDialogOptions | string, type: AlertType = 'info') => {
    if (typeof options === 'string') {
      const defaultTitle = {
        success: 'Berhasil',
        error: 'Terjadi Kesalahan',
        warning: 'Peringatan',
        info: 'Informasi',
      }[type]

      alertState.value = {
        open: true,
        title: defaultTitle,
        message: options,
        type,
        buttonText: 'OK',
      }
      return
    }

    const resolvedType = options.type ?? 'info'
    const defaultTitle = {
      success: 'Berhasil',
      error: 'Terjadi Kesalahan',
      warning: 'Peringatan',
      info: 'Informasi',
    }[resolvedType]

    alertState.value = {
      open: true,
      title: options.title ?? defaultTitle,
      message: options.message,
      type: resolvedType,
      buttonText: options.buttonText ?? 'OK',
      onClose: options.onClose,
    }
  }

  const closeAlert = () => {
    if (alertState.value?.onClose) {
      alertState.value.onClose()
    }
    alertState.value = null
  }

  return { showAlert, closeAlert, alertState }
}

export function useFlashAlert() {
  const { showAlert } = useAlert()
  const page = usePage()

  const checkAndShow = () => {
    const flash = page.props.flash as {
      success?: string
      error?: string
      warning?: string
      info?: string
    }

    if (flash?.success) {
      showAlert(flash.success, 'success')
    } else if (flash?.error) {
      showAlert(flash.error, 'error')
    } else if (flash?.warning) {
      showAlert(flash.warning, 'warning')
    } else if (flash?.info) {
      showAlert(flash.info, 'info')
    }
  }

  onMounted(() => checkAndShow())
  watch(() => page.props.flash, () => checkAndShow(), { deep: true })
}
