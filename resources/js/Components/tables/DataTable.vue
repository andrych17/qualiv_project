<!-- ponytail: DataTable + optional Status Rail (DESIGN.md signature) -->
<!-- ponytail: sort/selection/column-visibility are client concerns; pagination + actual data sort stay server-side via the host page's router.get. -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ChevronDown, ChevronUp, ChevronsUpDown, Columns3 } from 'lucide-vue-next'

type Column = {
  key: string
  label: string
  sortable?: boolean
  /** Backend sort param to send when this column differs from `key` (e.g. a formatted display column). */
  sortKey?: string
  align?: 'left' | 'center' | 'right'
}

type SortState = { key: string; direction: 'asc' | 'desc' } | null

const props = withDefaults(
  defineProps<{
    columns: Column[]
    items: Record<string, any>[]
    loading?: boolean
    emptyTitle?: string
    emptyDescription?: string
    /** When set, left edge of row uses semantic rail color from item[statusRailKey]. */
    statusRailKey?: string
    /** Field used to identify a row for selection. Defaults to 'id'. */
    rowKey?: string
    /** Shows a checkbox column and enables the selected model. */
    selectable?: boolean
    /** Sticks the header row to the top of the nearest scroll container. */
    stickyHeader?: boolean
    /** Unique key to persist column visibility in localStorage (e.g. 'legal.cases'). Omit to skip persistence. */
    storageKey?: string
  }>(),
  { rowKey: 'id' },
)

const sort = defineModel<SortState>('sort', { default: null })
const selected = defineModel<Array<string | number>>('selected', { default: () => [] })

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const railClass = (item: Record<string, any>) => {
  if (!props.statusRailKey) return ''
  const status = String(item[props.statusRailKey] ?? '').toLowerCase()
  const map: Record<string, string> = {
    open: 'border-l-[3px] border-l-signal-info',
    pending: 'border-l-[3px] border-l-signal-warning',
    closed: 'border-l-[3px] border-l-signal-success',
    active: 'border-l-[3px] border-l-signal-success',
    overdue: 'border-l-[3px] border-l-signal-danger',
    rejected: 'border-l-[3px] border-l-signal-danger',
  }
  return map[status] ?? 'border-l-[3px] border-l-border'
}

// --- Sort ---
const sortKeyOf = (column: Column) => column.sortKey ?? column.key

const toggleSort = (column: Column) => {
  if (!column.sortable) return
  const key = sortKeyOf(column)
  if (sort.value?.key !== key) {
    sort.value = { key, direction: 'asc' }
  } else if (sort.value.direction === 'asc') {
    sort.value = { key, direction: 'desc' }
  } else {
    sort.value = null
  }
}

const sortIcon = (column: Column) => {
  if (sort.value?.key !== sortKeyOf(column)) return ChevronsUpDown
  return sort.value.direction === 'asc' ? ChevronUp : ChevronDown
}

// --- Column visibility ---
const storagePrefix = 'datatable:hidden-columns:'

const loadHidden = (): string[] => {
  if (!props.storageKey) return []
  try {
    const raw = localStorage.getItem(storagePrefix + props.storageKey)
    return raw ? JSON.parse(raw) : []
  } catch {
    return []
  }
}

const hiddenKeys = ref<Set<string>>(new Set(loadHidden()))
const showColumnMenu = ref(false)

watch(
  hiddenKeys,
  (val) => {
    if (!props.storageKey) return
    localStorage.setItem(storagePrefix + props.storageKey, JSON.stringify([...val]))
  },
  { deep: true },
)

const visibleColumns = computed(() => props.columns.filter((c) => !hiddenKeys.value.has(c.key)))

const toggleColumn = (key: string) => {
  const next = new Set(hiddenKeys.value)
  if (next.has(key)) {
    next.delete(key)
  } else {
    if (props.columns.length - next.size <= 1) return // keep at least one column visible
    next.add(key)
  }
  hiddenKeys.value = next
}

const closeColumnMenu = (e: MouseEvent) => {
  if (!(e.target as HTMLElement)?.closest?.('[data-column-menu]')) {
    showColumnMenu.value = false
  }
}
onMounted(() => document.addEventListener('click', closeColumnMenu))
onUnmounted(() => document.removeEventListener('click', closeColumnMenu))

// --- Selection ---
const rowId = (item: Record<string, any>) => item[props.rowKey]

const isSelected = (item: Record<string, any>) => selected.value.includes(rowId(item))

const toggleRow = (item: Record<string, any>) => {
  const id = rowId(item)
  selected.value = isSelected(item)
    ? selected.value.filter((v) => v !== id)
    : [...selected.value, id]
}

const allSelected = computed(
  () => props.items.length > 0 && props.items.every((item) => isSelected(item)),
)
const someSelected = computed(
  () => !allSelected.value && props.items.some((item) => isSelected(item)),
)

const toggleAll = () => {
  selected.value = allSelected.value ? [] : props.items.map(rowId)
}
</script>

<template>
  <div class="space-y-2">
    <div v-if="selectable && selected.length > 0" class="flex items-center justify-between rounded-md border border-border bg-surface-50 px-4 py-2">
      <span class="text-sm font-medium text-ink-900">{{ selected.length }} selected</span>
      <div class="flex items-center gap-3">
        <slot name="bulk-actions" :selected="selected" />
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="selected = []">
          Clear
        </button>
      </div>
    </div>

    <div v-if="storageKey" class="flex justify-end">
      <div class="relative" data-column-menu>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-surface-50"
          @click="showColumnMenu = !showColumnMenu"
        >
          <Columns3 class="h-3.5 w-3.5" />
          Columns
        </button>
        <div
          v-if="showColumnMenu"
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
              @change="toggleColumn(column.key)"
            />
            {{ column.label }}
          </label>
        </div>
      </div>
    </div>

    <div class="overflow-hidden rounded-md border border-border bg-surface-0 shadow-sm">
      <div class="max-h-[70vh] overflow-auto">
        <table class="min-w-full divide-y divide-border">
          <thead class="bg-surface-50" :class="{ 'sticky top-0 z-10': stickyHeader }">
            <tr>
              <th v-if="selectable" class="w-10 px-4 py-3">
                <input
                  type="checkbox"
                  class="rounded border-border text-accent focus:ring-accent"
                  :checked="allSelected"
                  :indeterminate="someSelected"
                  @change="toggleAll"
                />
              </th>
              <th
                v-for="column in visibleColumns"
                :key="column.key"
                class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-ink-600"
                :class="[alignClass(column.align), column.sortable ? 'cursor-pointer select-none hover:text-ink-900' : '']"
                @click="toggleSort(column)"
              >
                <span class="inline-flex items-center gap-1" :class="{ 'flex-row-reverse': column.align === 'right' }">
                  {{ column.label }}
                  <component :is="sortIcon(column)" v-if="column.sortable" class="h-3.5 w-3.5" />
                </span>
              </th>
            </tr>
          </thead>

          <tbody class="divide-y divide-border bg-surface-0">
            <tr v-if="loading">
              <td :colspan="visibleColumns.length + (selectable ? 1 : 0)" class="px-4 py-8 text-center text-sm text-ink-600">
                Loading…
              </td>
            </tr>

            <tr v-else-if="items.length === 0">
              <td :colspan="visibleColumns.length + (selectable ? 1 : 0)" class="px-4 py-10 text-center">
                <slot name="empty">
                  <div class="space-y-1">
                    <p class="text-sm font-medium text-ink-900">
                      {{ emptyTitle ?? 'Nothing here yet' }}
                    </p>
                    <p class="text-sm text-ink-600">
                      {{ emptyDescription ?? 'Add the first item to get started.' }}
                    </p>
                  </div>
                </slot>
              </td>
            </tr>

            <tr
              v-else
              v-for="item in items"
              :key="rowId(item)"
              class="hover:bg-surface-50"
              :class="railClass(item)"
            >
              <td v-if="selectable" class="w-10 px-4 py-3">
                <input
                  type="checkbox"
                  class="rounded border-border text-accent focus:ring-accent"
                  :checked="isSelected(item)"
                  @change="toggleRow(item)"
                />
              </td>
              <td
                v-for="column in visibleColumns"
                :key="column.key"
                class="whitespace-nowrap px-4 py-3 text-sm text-ink-900"
                :class="alignClass(column.align)"
              >
                <slot :name="`cell-${column.key}`" :item="item" :value="item[column.key]">
                  {{ item[column.key] }}
                </slot>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
