// ponytail: Module-scope sidebar state for scroll position, accordion state, and collapse toggle.
// openMenus and isCollapsed are shared refs rather than plain values because the desktop nav
// and the mobile drawer are two separate SidebarNav instances that must agree on what is open.
import { ref, watch } from 'vue'

const STORAGE_KEY = 'erp_sidebar_collapsed'

const readCollapsed = (): boolean => {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'true'
  } catch {
    // Private mode or blocked storage — fall back to expanded.
    return false
  }
}

export const openMenus = ref<Record<string, boolean>>({})
export const isCollapsed = ref(readCollapsed())

watch(isCollapsed, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, String(value))
  } catch {
    // Ignore localStorage errors
  }
})

// Scroll is deliberately not persisted to localStorage: it should survive an Inertia page
// swap within a session, not come back stale on a fresh load.
let scrollTop = 0

export function getSavedScroll(): number {
  return scrollTop
}

export function setSavedScroll(value: number): void {
  scrollTop = value
}
