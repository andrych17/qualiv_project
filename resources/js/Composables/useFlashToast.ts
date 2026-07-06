// ponytail: Minimal toast state store using global reactive ref to avoid complex event buses
import { ref, watch, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

export const activeToast = ref<{ message: string; type: 'success' | 'error' } | null>(null)

export function showToast(message: string, type: 'success' | 'error' = 'success') {
  activeToast.value = { message, type }
  setTimeout(() => {
    if (activeToast.value?.message === message) {
      activeToast.value = null
    }
  }, 3000)
}

export function useFlashToast() {
  const page = usePage()

  const checkAndShow = () => {
    const flash = page.props.flash as { success?: string; error?: string }
    if (flash?.success) {
      showToast(flash.success, 'success')
    }
    if (flash?.error) {
      showToast(flash.error, 'error')
    }
  }

  onMounted(() => checkAndShow())
  watch(() => page.props.flash, () => checkAndShow(), { deep: true })
}
