// Bridge toast triggers to global centered AlertDialog and provide backward compatibility
import { ref, watch, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useAlert } from '@/Composables/useAlertDialog'

export const activeToast = ref<{ message: string; type: 'success' | 'error' } | null>(null)

export function showToast(message: string, type: 'success' | 'error' = 'success') {
  const { showAlert } = useAlert()
  showAlert({
    message,
    type,
    title: type === 'success' ? 'Berhasil' : 'Terjadi Kesalahan',
  })
}

export function useFlashToast() {
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
      showAlert({ message: flash.success, type: 'success', title: 'Berhasil' })
    }
    if (flash?.error) {
      showAlert({ message: flash.error, type: 'error', title: 'Terjadi Kesalahan' })
    }
    if (flash?.warning) {
      showAlert({ message: flash.warning, type: 'warning', title: 'Peringatan' })
    }
    if (flash?.info) {
      showAlert({ message: flash.info, type: 'info', title: 'Informasi' })
    }
  }

  onMounted(() => checkAndShow())
  watch(() => page.props.flash, () => checkAndShow(), { deep: true })
}

