<!-- ponytail: Search + FilterBuilder + SavedViews + ColumnVisibility + Export, one row above the table. -->
<!-- ponytail: FilterBuilder is a fixed set of whitelisted fields (select/text), not a generic
     field+operator+value query builder — it reuses the same params each controller's scopeFilter()
     already whitelists, so no new backend query surface is introduced. -->
<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { SlidersHorizontal, Bookmark, Columns3, Download, X } from 'lucide-vue-next'
import SearchInput from '@/Components/filters/SearchInput.vue'
import type { SavedView, SortState } from '@/Composables/useDataTable'

export type FilterFieldDef = {
  key: string
  label: string
  type: 'text' | 'select' | 'date'
  options?: Array<{ label: string; value: string }>
}

type Column = { key: string; label: string }

const props = defineProps<{
  columns: Column[]
  hiddenKeys: Set<string>
  filterFields?: FilterFieldDef[]
  savedViews?: SavedView[]
  searchPlaceholder?: string
  exportable?: boolean
  currentSort: SortState
}>()

const emit = defineEmits<{
  'toggle-column': [key: string]
  'apply-view': [view: SavedView]
  'save-view': [name: string]
  'delete-view': [name: string]
  export: []
}>()

const search = defineModel<string>('search', { default: '' })
const filters = defineModel<Record<string, any>>('filters', { default: () => ({}) })

const activeFilterCount = computed(
  () => Object.values(filters.value ?? {}).filter((v) => v !== '' && v != null).length,
)

const openPopover = ref<'filters' | 'views' | 'columns' | null>(null)
const togglePopover = (name: 'filters' | 'views' | 'columns') => {
  openPopover.value = openPopover.value === name ? null : name
}

const closeOnOutsideClick = (e: MouseEvent) => {
  if (!(e.target as HTMLElement)?.closest?.('[data-dt-popover]')) {
    openPopover.value = null
  }
}
onMounted(() => document.addEventListener('click', closeOnOutsideClick))
onUnmounted(() => document.removeEventListener('click', closeOnOutsideClick))

const clearFilters = () => {
  filters.value = {}
}

const newViewName = ref('')
const submitSaveView = () => {
  const name = newViewName.value.trim()
  if (!name) return
  emit('save-view', name)
  newViewName.value = ''
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="w-full sm:max-w-xs">
      <SearchInput v-model="search" :placeholder="searchPlaceholder ?? 'Search…'" />
    </div>

    <div v-if="filterFields?.length" class="relative" data-dt-popover>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-surface-50"
        @click="togglePopover('filters')"
      >
        <SlidersHorizontal class="h-3.5 w-3.5" />
        Filters
        <span v-if="activeFilterCount > 0" class="ml-0.5 rounded-full bg-accent px-1.5 text-[10px] font-semibold text-white">
          {{ activeFilterCount }}
        </span>
      </button>
      <div
        v-if="openPopover === 'filters'"
        class="absolute left-0 z-20 mt-1 w-64 space-y-3 rounded-md border border-border bg-surface-0 p-3 shadow-lg"
      >
        <div v-for="field in filterFields" :key="field.key" class="space-y-1">
          <label class="text-xs font-medium text-ink-600">{{ field.label }}</label>
          <select
            v-if="field.type === 'select'"
            v-model="filters[field.key]"
            class="w-full rounded-md border border-border py-1.5 text-sm"
          >
            <option value="">All</option>
            <option v-for="opt in field.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
          <input
            v-else-if="field.type === 'date'"
            v-model="filters[field.key]"
            type="date"
            class="w-full rounded-md border border-border py-1.5 text-sm"
          />
          <input
            v-else
            v-model="filters[field.key]"
            type="text"
            class="w-full rounded-md border border-border py-1.5 text-sm"
          />
        </div>
        <button
          v-if="activeFilterCount > 0"
          type="button"
          class="flex items-center gap-1 text-xs font-medium text-ink-600 hover:text-signal-danger"
          @click="clearFilters"
        >
          <X class="h-3 w-3" />
          Clear filters
        </button>
      </div>
    </div>

    <div v-if="savedViews !== undefined" class="relative" data-dt-popover>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-surface-50"
        @click="togglePopover('views')"
      >
        <Bookmark class="h-3.5 w-3.5" />
        Views
      </button>
      <div
        v-if="openPopover === 'views'"
        class="absolute left-0 z-20 mt-1 w-56 space-y-2 rounded-md border border-border bg-surface-0 p-3 shadow-lg"
      >
        <div v-if="!savedViews?.length" class="text-xs text-ink-600">No saved views yet.</div>
        <div v-for="view in savedViews" :key="view.name" class="flex items-center justify-between gap-2">
          <button type="button" class="truncate text-sm text-ink-900 hover:text-accent" @click="emit('apply-view', view)">
            {{ view.name }}
          </button>
          <button type="button" class="text-ink-600 hover:text-signal-danger" @click="emit('delete-view', view.name)">
            <X class="h-3.5 w-3.5" />
          </button>
        </div>
        <div class="flex items-center gap-1 border-t border-border pt-2">
          <input
            v-model="newViewName"
            type="text"
            placeholder="Save current as…"
            class="w-full rounded-md border border-border py-1 text-xs"
            @keyup.enter="submitSaveView"
          />
          <button type="button" class="text-xs font-medium text-accent" @click="submitSaveView">Save</button>
        </div>
      </div>
    </div>

    <div class="relative" data-dt-popover>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-surface-50"
        @click="togglePopover('columns')"
      >
        <Columns3 class="h-3.5 w-3.5" />
        Columns
      </button>
      <div
        v-if="openPopover === 'columns'"
        class="absolute right-0 z-20 mt-1 w-48 rounded-md border border-border bg-surface-0 py-1 shadow-lg"
      >
        <label
          v-for="column in columns"
          :key="column.key"
          class="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm text-ink-900 hover:bg-surface-50"
        >
          <input
            type="checkbox"
            class="rounded border-border text-accent focus:ring-accent"
            :checked="!hiddenKeys.has(column.key)"
            @change="emit('toggle-column', column.key)"
          />
          {{ column.label }}
        </label>
      </div>
    </div>

    <button
      v-if="exportable"
      type="button"
      class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-surface-50"
      @click="emit('export')"
    >
      <Download class="h-3.5 w-3.5" />
      Export
    </button>
  </div>
</template>
