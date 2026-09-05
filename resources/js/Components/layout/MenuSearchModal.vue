<!-- ponytail: Global Command Palette & Menu Search Modal with Ctrl+Space shortcut and category filters -->
<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch, type Component } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import * as LucideIcons from 'lucide-vue-next'
import {
  Search,
  X,
  CornerDownLeft,
  ChevronRight,
  SearchX,
  Sparkles,
  HelpCircle,
  Compass,
} from 'lucide-vue-next'
import { useMenuSearch } from '@/Composables/useMenuSearch'

interface Level3MenuItem {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
}

interface Level2MenuItem {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  children?: Level3MenuItem[]
}

interface MenuItem {
  code: string
  label: string
  href: string
  icon: string | null
  seq: number
  header: string | null
  children?: Level2MenuItem[]
}

export interface FlatMenuItem {
  id: string
  code: string
  label: string
  href: string
  icon: string | null
  section: string
  breadcrumbText: string
  level: number
}

const page = usePage()
const { isMenuSearchOpen, closeMenuSearch } = useMenuSearch()

const searchQuery = ref('')
const selectedCategory = ref<string>('ALL')
const selectedIndex = ref(0)
const searchInputRef = ref<HTMLInputElement | null>(null)
const itemRefs = ref<HTMLElement[]>([])

const getIcon = (name: string | null): Component => {
  if (!name) return HelpCircle
  const icons = LucideIcons as unknown as Record<string, Component>
  if (icons[name]) return icons[name]

  const pascal = name
    .split(/[-_]/)
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase())
    .join('')
  return icons[pascal] ?? HelpCircle
}

// Flatten nested menu hierarchy into direct searchable items
const allMenuItems = computed((): FlatMenuItem[] => {
  const rawMenus = (page.props.navMenus as MenuItem[] | undefined) ?? []
  const flat: FlatMenuItem[] = []

  for (const item of rawMenus) {
    const section = (item.header || 'General').trim() || 'General'

    if (item.children && item.children.length > 0) {
      for (const child of item.children) {
        if (child.children && child.children.length > 0) {
          for (const grandchild of child.children) {
            if (grandchild.href && grandchild.href !== '#') {
              flat.push({
                id: `${item.code}_${child.code}_${grandchild.code}`,
                code: grandchild.code,
                label: grandchild.label,
                href: grandchild.href,
                icon: grandchild.icon || child.icon || item.icon,
                section,
                breadcrumbText: `${item.label} › ${child.label}`,
                level: 3,
              })
            }
          }
        } else if (child.href && child.href !== '#') {
          flat.push({
            id: `${item.code}_${child.code}`,
            code: child.code,
            label: child.label,
            href: child.href,
            icon: child.icon || item.icon,
            section,
            breadcrumbText: item.label,
            level: 2,
          })
        }
      }
    } else if (item.href && item.href !== '#') {
      flat.push({
        id: item.code,
        code: item.code,
        label: item.label,
        href: item.href,
        icon: item.icon,
        section,
        breadcrumbText: section,
        level: 1,
      })
    }
  }

  return flat
})

// Extract available categories with counts
const categories = computed(() => {
  const map = new Map<string, number>()
  for (const item of allMenuItems.value) {
    const count = map.get(item.section) ?? 0
    map.set(item.section, count + 1)
  }

  const list = Array.from(map.entries()).map(([name, count]) => ({
    name,
    count,
  }))

  return [{ name: 'ALL', label: 'Semua Menu', count: allMenuItems.value.length }, ...list.map((c) => ({ ...c, label: c.name }))]
})

// Filter items based on active search query and category filter
const filteredItems = computed((): FlatMenuItem[] => {
  const query = searchQuery.value.trim().toLowerCase()
  let list = allMenuItems.value

  if (selectedCategory.value !== 'ALL') {
    list = list.filter((item) => item.section === selectedCategory.value)
  }

  if (!query) {
    return list
  }

  return list.filter((item) => {
    const labelMatch = item.label.toLowerCase().includes(query)
    const codeMatch = item.code.toLowerCase().includes(query)
    const breadcrumbMatch = item.breadcrumbText.toLowerCase().includes(query)
    const sectionMatch = item.section.toLowerCase().includes(query)
    return labelMatch || codeMatch || breadcrumbMatch || sectionMatch
  })
})

// Grouped items for "Show All" empty-query browsing
const groupedItems = computed(() => {
  const groups: Record<string, FlatMenuItem[]> = {}
  for (const item of filteredItems.value) {
    if (!groups[item.section]) {
      groups[item.section] = []
    }
    groups[item.section].push(item)
  }
  return groups
})

// Reset selection index when query or category changes
watch([searchQuery, selectedCategory], () => {
  selectedIndex.value = 0
})

// Handle auto focus and body scroll locking when opened
watch(isMenuSearchOpen, (isOpen) => {
  if (isOpen) {
    searchQuery.value = ''
    selectedCategory.value = 'ALL'
    selectedIndex.value = 0
    document.body.style.overflow = 'hidden'
    nextTick(() => {
      searchInputRef.value?.focus()
    })
  } else {
    document.body.style.overflow = ''
  }
})

const navigateToItem = (item: FlatMenuItem) => {
  closeMenuSearch()
  router.visit(item.href)
}

const selectCategory = (categoryName: string) => {
  selectedCategory.value = categoryName
  selectedIndex.value = 0
  searchInputRef.value?.focus()
}

const clearSearch = () => {
  searchQuery.value = ''
  selectedIndex.value = 0
  searchInputRef.value?.focus()
}

// Keyboard navigation in modal list
const handleKeydown = (e: KeyboardEvent) => {
  if (!isMenuSearchOpen.value) return

  const total = filteredItems.value.length

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (total > 0) {
      selectedIndex.value = (selectedIndex.value + 1) % total
      scrollToActiveItem()
    }
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (total > 0) {
      selectedIndex.value = (selectedIndex.value - 1 + total) % total
      scrollToActiveItem()
    }
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (total > 0 && filteredItems.value[selectedIndex.value]) {
      navigateToItem(filteredItems.value[selectedIndex.value])
    }
  } else if (e.key === 'Escape') {
    e.preventDefault()
    closeMenuSearch()
  }
}

const scrollToActiveItem = () => {
  nextTick(() => {
    const el = itemRefs.value[selectedIndex.value]
    if (el) {
      el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    }
  })
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="isMenuSearchOpen"
        class="fixed inset-0 z-50 flex items-start justify-center p-3 sm:p-6 md:pt-20 bg-ink-900/60 backdrop-blur-sm overflow-y-auto"
        @click.self="closeMenuSearch"
      >
        <!-- Modal Card Dialog -->
        <div
          class="relative w-full max-w-2xl bg-surface-0 rounded-xl shadow-2xl border border-border overflow-hidden flex flex-col max-h-[85vh] transition-all transform animate-in fade-in zoom-in-95 duration-150"
          role="dialog"
          aria-modal="true"
          aria-label="Search Menu ERP"
          @click.stop
        >
          <!-- Search Header Input Bar -->
          <div class="relative flex items-center px-4 py-3.5 border-b border-border bg-surface-0 gap-3">
            <Search class="h-5 w-5 text-accent shrink-0" />
            <input
              ref="searchInputRef"
              v-model="searchQuery"
              type="text"
              class="flex-1 bg-transparent text-sm sm:text-base text-ink-900 placeholder:text-ink-600/60 outline-none border-none p-0 focus:ring-0 font-medium"
              placeholder="Cari semua menu, modul, atau halaman... (Ctrl + Space)"
              autocomplete="off"
              spellcheck="false"
            />

            <!-- Clear button / Escape badge -->
            <button
              v-if="searchQuery"
              type="button"
              class="p-1 rounded-md text-ink-600 hover:text-ink-900 hover:bg-surface-50 transition-colors cursor-pointer"
              title="Hapus pencarian"
              @click="clearSearch"
            >
              <X class="h-4 w-4" />
            </button>
            <span
              v-else
              class="hidden sm:inline-flex items-center gap-1 text-[10px] font-mono text-ink-600 bg-surface-50 border border-border px-1.5 py-0.5 rounded shadow-2xs"
            >
              ESC
            </span>
          </div>

          <!-- Category Filter Pills Bar -->
          <div
            v-if="categories.length > 1"
            class="px-4 py-2 border-b border-border bg-surface-50 flex items-center gap-1.5 overflow-x-auto no-scrollbar shrink-0 select-none"
          >
            <button
              v-for="cat in categories"
              :key="cat.name"
              type="button"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium transition-all whitespace-nowrap cursor-pointer shrink-0"
              :class="
                selectedCategory === cat.name
                  ? 'bg-accent text-accent-text font-semibold shadow-2xs'
                  : 'bg-surface-0 text-ink-600 hover:text-ink-900 hover:bg-surface-100 border border-border'
              "
              @click="selectCategory(cat.name)"
            >
              <span>{{ cat.label }}</span>
              <span
                class="px-1.5 py-0.2 rounded-full text-[10px]"
                :class="selectedCategory === cat.name ? 'bg-white/20 text-white' : 'bg-surface-50 text-ink-500'"
              >
                {{ cat.count }}
              </span>
            </button>
          </div>

          <!-- Menu Items List -->
          <div class="flex-1 overflow-y-auto p-2 sm:p-3 space-y-1 divide-y divide-border/30 min-h-[220px]">
            <!-- When Search Query matches results -->
            <template v-if="filteredItems.length > 0">
              <!-- If searching with query: flat list with count badge -->
              <div v-if="searchQuery.trim()" class="pb-1 px-2 pt-1 flex items-center justify-between text-xs text-ink-600 font-medium">
                <span>Hasil Pencarian ({{ filteredItems.length }})</span>
                <span class="text-[11px] text-ink-500">Gunakan ↑↓ untuk navigasi</span>
              </div>

              <!-- Item Entries -->
              <div
                v-for="(item, index) in filteredItems"
                :key="item.id"
                :ref="(el) => { if (el) itemRefs[index] = el as HTMLElement }"
                class="group flex items-center justify-between p-2.5 sm:p-3 rounded-lg text-left transition-all duration-100 cursor-pointer select-none"
                :class="
                  selectedIndex === index
                    ? 'bg-accent/10 border border-accent/40 text-ink-900 shadow-2xs ring-1 ring-accent/30 font-semibold'
                    : 'hover:bg-surface-50 border border-transparent text-ink-700'
                "
                @mouseenter="selectedIndex = index"
                @click="navigateToItem(item)"
              >
                <div class="flex items-center gap-3 min-w-0 flex-1">
                  <div
                    class="h-9 w-9 rounded-lg flex items-center justify-center border shrink-0 transition-colors"
                    :class="
                      selectedIndex === index
                        ? 'bg-accent text-accent-text border-accent shadow-2xs'
                        : 'bg-surface-50 border-border text-ink-600 group-hover:border-accent/40 group-hover:text-accent'
                    "
                  >
                    <component :is="getIcon(item.icon)" class="h-4 w-4" />
                  </div>

                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                      <p
                        class="text-sm truncate"
                        :class="selectedIndex === index ? 'font-bold text-ink-900' : 'font-medium text-ink-900'"
                      >
                        {{ item.label }}
                      </p>
                    </div>

                    <p class="text-[11px] text-ink-600 flex items-center gap-1 truncate mt-0.5">
                      <span class="font-medium text-ink-600/80">{{ item.section }}</span>
                      <ChevronRight class="h-3 w-3 text-ink-400 shrink-0" />
                      <span class="truncate">{{ item.breadcrumbText }}</span>
                    </p>
                  </div>
                </div>

                <div class="flex items-center gap-2 pl-2 shrink-0">
                  <span
                    class="hidden sm:inline-block text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded border"
                    :class="
                      selectedIndex === index
                        ? 'bg-accent/15 text-accent border-accent/30'
                        : 'bg-surface-50 text-ink-600 border-border'
                    "
                  >
                    {{ item.section }}
                  </span>

                  <CornerDownLeft
                    class="h-4 w-4 transition-transform duration-100"
                    :class="
                      selectedIndex === index
                        ? 'text-accent translate-x-0.5 opacity-100'
                        : 'text-ink-400 opacity-0 group-hover:opacity-60'
                    "
                  />
                </div>
              </div>
            </template>

            <!-- Empty Search State -->
            <div
              v-else
              class="flex flex-col items-center justify-center py-12 px-4 text-center select-none"
            >
              <div class="h-12 w-12 rounded-full bg-surface-50 border border-border flex items-center justify-center text-ink-400 mb-3">
                <SearchX class="h-6 w-6" />
              </div>
              <p class="text-sm font-semibold text-ink-900">Menu tidak ditemukan</p>
              <p class="text-xs text-ink-600 mt-1 max-w-sm">
                Tidak ada menu yang sesuai dengan kata kunci
                <span class="font-medium text-ink-900">"{{ searchQuery }}"</span>.
              </p>
              <button
                type="button"
                class="mt-4 px-3 py-1.5 rounded-lg border border-border bg-surface-50 text-xs font-medium text-ink-700 hover:text-ink-900 hover:bg-surface-100 transition-colors cursor-pointer"
                @click="clearSearch"
              >
                Lihat Semua Menu
              </button>
            </div>
          </div>

          <!-- Footer Information & Keyboard Help -->
          <div class="border-t border-border bg-surface-50 px-4 py-2.5 flex items-center justify-between text-[11px] text-ink-600 shrink-0 select-none">
            <div class="flex items-center gap-3">
              <span class="inline-flex items-center gap-1">
                <kbd class="px-1.5 py-0.5 rounded bg-surface-0 border border-border font-mono shadow-2xs text-[10px]">↑</kbd>
                <kbd class="px-1.5 py-0.5 rounded bg-surface-0 border border-border font-mono shadow-2xs text-[10px]">↓</kbd>
                <span>Navigasi</span>
              </span>
              <span class="inline-flex items-center gap-1">
                <kbd class="px-1.5 py-0.5 rounded bg-surface-0 border border-border font-mono shadow-2xs text-[10px]">↵</kbd>
                <span>Buka</span>
              </span>
              <span class="hidden sm:inline-flex items-center gap-1">
                <kbd class="px-1.5 py-0.5 rounded bg-surface-0 border border-border font-mono shadow-2xs text-[10px]">Esc</kbd>
                <span>Tutup</span>
              </span>
            </div>

            <div class="flex items-center gap-1.5 font-medium text-ink-600">
              <Compass class="h-3.5 w-3.5 text-accent" />
              <span>{{ allMenuItems.length }} Menu Tersedia</span>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
