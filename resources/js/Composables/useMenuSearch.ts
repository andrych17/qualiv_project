// ponytail: Global reactive menu search state and shortcut listener
import { ref, onMounted, onUnmounted } from 'vue'

export const isMenuSearchOpen = ref(false)

export function openMenuSearch(): void {
  isMenuSearchOpen.value = true
}

export function closeMenuSearch(): void {
  isMenuSearchOpen.value = false
}

export function toggleMenuSearch(): void {
  isMenuSearchOpen.value = !isMenuSearchOpen.value
}

export function useMenuSearch() {
  return {
    isMenuSearchOpen,
    openMenuSearch,
    closeMenuSearch,
    toggleMenuSearch,
  }
}

/**
 * Global keyboard shortcut listener for Ctrl + Space (and Cmd + Space / Ctrl + K)
 */
export function useMenuSearchShortcut() {
  const onKeydown = (e: KeyboardEvent) => {
    // Ctrl + Space or Meta + Space (Cmd + Space on Mac)
    const isCtrlOrMeta = e.ctrlKey || e.metaKey
    const isSpace = e.code === 'Space' || e.key === ' ' || e.key === 'Spacebar'
    const isK = e.code === 'KeyK' || e.key === 'k' || e.key === 'K'

    if (isCtrlOrMeta && (isSpace || isK)) {
      e.preventDefault()
      e.stopPropagation()
      toggleMenuSearch()
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', onKeydown, true)
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown, true)
  })
}
