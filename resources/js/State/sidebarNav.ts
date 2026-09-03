// True module-scope state — unlike a `<script setup>` block (which compiles into the
// component's setup() function and therefore re-initializes on every mount), a plain .ts
// module is only evaluated once per browser session, so state kept here survives every
// remount AppSidebar.vue goes through on Inertia navigation (AppLayout is wrapped inline
// per-page, not a persistent layout — see AppSidebar.vue's own docblock).
let scrollTop = 0
let openMenus: Record<string, boolean> = {}

export function getSavedScroll(): number {
  return scrollTop
}

export function setSavedScroll(value: number): void {
  scrollTop = value
}

export function getSavedOpenMenus(): Record<string, boolean> {
  return openMenus
}

export function setSavedOpenMenus(value: Record<string, boolean>): void {
  openMenus = { ...value }
}
