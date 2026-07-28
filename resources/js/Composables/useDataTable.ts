// ponytail: owns DataTable's ViewState (hidden columns, column widths) and SavedViews,
// persisted to localStorage. QueryState (search/filters/sort) and SelectionState (selected)
// stay as defineModel in DataTable.vue itself — they're two-way bound to the host page's
// refs (which drive the Inertia router.get), so folding them into this composable too would
// just add indirection without removing any duplication.
import { ref, watch } from 'vue'

export type SortState = { key: string; direction: 'asc' | 'desc' } | null

export interface SavedView {
  name: string
  search: string
  filters: Record<string, any>
  sort: SortState
}

interface UseDataTableOptions {
  storageKey?: string
  columnCount: number
}

function storageId(storageKey: string, part: string) {
  return `datatable:${part}:${storageKey}`
}

function loadJSON<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key)
    return raw ? JSON.parse(raw) : fallback
  } catch {
    return fallback
  }
}

export function useDataTable(options: UseDataTableOptions) {
  const { storageKey, columnCount } = options

  const hiddenKeys = ref<Set<string>>(
    new Set(storageKey ? loadJSON<string[]>(storageId(storageKey, 'hidden-columns'), []) : []),
  )
  const columnWidths = ref<Record<string, number>>(
    storageKey ? loadJSON(storageId(storageKey, 'column-widths'), {}) : {},
  )
  const savedViews = ref<SavedView[]>(
    storageKey ? loadJSON<SavedView[]>(storageId(storageKey, 'views'), []) : [],
  )

  if (storageKey) {
    watch(hiddenKeys, (v) => localStorage.setItem(storageId(storageKey, 'hidden-columns'), JSON.stringify([...v])), { deep: true })
    watch(columnWidths, (v) => localStorage.setItem(storageId(storageKey, 'column-widths'), JSON.stringify(v)), { deep: true })
    watch(savedViews, (v) => localStorage.setItem(storageId(storageKey, 'views'), JSON.stringify(v)), { deep: true })
  }

  const toggleColumn = (key: string) => {
    const next = new Set(hiddenKeys.value)
    if (next.has(key)) {
      next.delete(key)
    } else {
      if (columnCount - next.size <= 1) return // keep at least one column visible
      next.add(key)
    }
    hiddenKeys.value = next
  }

  const setColumnWidth = (key: string, width: number) => {
    columnWidths.value = { ...columnWidths.value, [key]: Math.max(60, Math.round(width)) }
  }

  const saveView = (name: string, state: Omit<SavedView, 'name'>) => {
    savedViews.value = [...savedViews.value.filter((v) => v.name !== name), { name, ...state }]
  }

  const deleteView = (name: string) => {
    savedViews.value = savedViews.value.filter((v) => v.name !== name)
  }

  return {
    hiddenKeys,
    columnWidths,
    savedViews,
    toggleColumn,
    setColumnWidth,
    saveView,
    deleteView,
  }
}
