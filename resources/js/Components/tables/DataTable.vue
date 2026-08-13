<!-- ponytail: DataTable orchestrator — composes Toolbar/Header/Body/Footer + useDataTable().
     QueryState (search/filters/sort) and SelectionState (selected) are host-owned defineModels
     (the host page's router.get is the source of truth for what's actually loaded server-side).
     ViewState (hidden columns/column widths) and SavedViews/Persistence live in useDataTable(). -->
<script setup lang="ts">
import { computed, ref, useSlots } from 'vue'
import {
  useDataTable,
  aggregateColumn,
  formatAggregate,
  type SortState,
  type SavedView,
  type FooterAggregate,
} from '@/Composables/useDataTable'
import DataTableToolbar, { type FilterFieldDef } from '@/Components/tables/DataTableToolbar.vue'
import DataTableHeader from '@/Components/tables/DataTableHeader.vue'
import DataTableBody from '@/Components/tables/DataTableBody.vue'
import DataTableFooter from '@/Components/tables/DataTableFooter.vue'

export type { FilterFieldDef, SortState, SavedView, FooterAggregate }

type Column = {
  key: string
  label: string
  sortable?: boolean
  /** Backend sort param to send when this column differs from `key` (e.g. a formatted display column). */
  sortKey?: string
  align?: 'left' | 'center' | 'right'
  /** Built-in aggregate for this column's group-subtotal / grand-total cell (Excel-style Subtotal). */
  footer?: FooterAggregate
}

type PaginationLink = { url: string | null; label: string; active: boolean }

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
    /** Unique key to persist column visibility/widths/saved views in localStorage (e.g. 'legal.cases'). */
    storageKey?: string
    /** FilterBuilder field defs — reuses the same whitelisted params each controller's scopeFilter() accepts. */
    filterFields?: FilterFieldDef[]
    searchPlaceholder?: string
    /** Enables drag-to-resize column width handles. */
    resizable?: boolean
    /** Freezes this many leading visible columns (after selection) while scrolling horizontally. */
    stickyColumnCount?: number
    /** Columns that open an inline text editor on double-click; emits `cell-edit` on commit. */
    editableKeys?: string[]
    /** Shows an expand toggle per row; expanded content renders via the #row-detail slot. */
    expandable?: boolean
    /** Filename for the Export CSV button (current loaded page only — bump per-page size to export more). */
    exportFilename?: string
    /** Groups rows like Excel's Group/Outline: a column key, or a resolver returning {key,label}. */
    groupBy?: string | ((item: Record<string, any>) => { key: string | number; label: string })
    /** Groups start collapsed instead of expanded. */
    defaultCollapsedGroups?: boolean
    /**
     * Grand-total row values, keyed by column key — overrides client-side aggregation for that
     * column. Required for a true grand total in serverSide/paginated mode, where `items` is only
     * the current page: pass the totals computed by the backend across the full result set.
     */
    footerTotals?: Record<string, number | string>
    // Footer / pagination meta — pass straight from the Laravel paginator (already has these fields).
    total?: number
    from?: number | null
    to?: number | null
    links?: PaginationLink[]
    perPageOptions?: number[]
  }>(),
  { rowKey: 'id' },
)

const emit = defineEmits<{
  'cell-edit': [item: Record<string, any>, key: string, value: string]
}>()

const bodyRef = ref<InstanceType<typeof DataTableBody> | null>(null)

const sort = defineModel<SortState>('sort', { default: null })
const selected = defineModel<Array<string | number>>('selected', { default: () => [] })
const search = defineModel<string>('search', { default: '' })
const filters = defineModel<Record<string, any>>('filters', { default: () => ({}) })
const perPage = defineModel<number | undefined>('perPage')

const slots = useSlots()
// Only forward per-column cell slots the host page actually provided — an always-registered
// but empty slot would shadow DataTableBody's own default cell rendering (v-if can't combine
// with v-for on the same <template>, so filter the list instead of guarding inline).
const forwardedCellColumns = computed(() => visibleColumns.value.filter((c) => !!slots[`cell-${c.key}`]))
const forwardedFooterColumns = computed(() => visibleColumns.value.filter((c) => !!slots[`footer-${c.key}`]))

// --- Grand total footer ---
const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const hasFooterRow = computed(
  () => visibleColumns.value.some((c) => c.footer) || !!props.footerTotals,
)

const grandTotals = computed<Record<string, number>>(() => {
  const result: Record<string, number> = {}
  for (const column of visibleColumns.value) {
    if (column.footer) result[column.key] = aggregateColumn(props.items, column.key, column.footer)
  }
  return result
})

const { hiddenKeys, columnWidths, savedViews, toggleColumn, setColumnWidth, saveView, deleteView } =
  useDataTable({ storageKey: props.storageKey, columnCount: props.columns.length })

const visibleColumns = computed(() => props.columns.filter((c) => !hiddenKeys.value.has(c.key)))

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

// --- Sticky columns: Header measures <th> widths, Body aligns matching <td> offsets ---
const stickyOffsets = ref<number[]>([])

// --- Saved views ---
const applyView = (view: SavedView) => {
  search.value = view.search
  filters.value = { ...view.filters }
  sort.value = view.sort
}

const submitSaveView = (name: string) => {
  saveView(name, { search: search.value, filters: { ...filters.value }, sort: sort.value })
}

// --- Export current page to CSV ---
const exportCsv = () => {
  const cols = visibleColumns.value
  const escape = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const rows = [
    cols.map((c) => escape(c.label)).join(','),
    ...props.items.map((item) => cols.map((c) => escape(item[c.key])).join(',')),
  ]
  const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${props.exportFilename ?? 'export'}.csv`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="space-y-3">
    <DataTableToolbar
      v-if="filterFields?.length || storageKey || exportFilename"
      v-model:search="search"
      v-model:filters="filters"
      :columns="columns"
      :hidden-keys="hiddenKeys"
      :filter-fields="filterFields"
      :saved-views="storageKey ? savedViews : undefined"
      :search-placeholder="searchPlaceholder"
      :exportable="!!exportFilename"
      :current-sort="sort"
      @toggle-column="toggleColumn"
      @apply-view="applyView"
      @save-view="submitSaveView"
      @delete-view="deleteView"
      @export="exportCsv"
    />

    <div v-if="selectable && selected.length > 0" class="flex items-center justify-between rounded-md border border-border bg-surface-50 px-4 py-2">
      <span class="text-sm font-medium text-ink-900">{{ selected.length }} selected</span>
      <div class="flex items-center gap-3">
        <slot name="bulk-actions" :selected="selected" />
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="selected = []">
          Clear
        </button>
      </div>
    </div>

    <div v-if="groupBy" class="flex items-center justify-end gap-3 text-sm">
      <button type="button" class="text-ink-600 hover:text-ink-900" @click="bodyRef?.expandAllGroups()">
        Expand all
      </button>
      <button type="button" class="text-ink-600 hover:text-ink-900" @click="bodyRef?.collapseAllGroups()">
        Collapse all
      </button>
    </div>

    <div class="overflow-hidden rounded-md border border-border bg-surface-0 shadow-sm">
      <div class="max-h-[70vh] overflow-auto">
        <table class="min-w-full divide-y divide-border">
          <DataTableHeader
            :columns="visibleColumns"
            :sort="sort"
            :selectable="selectable"
            :expandable="expandable"
            :all-selected="allSelected"
            :some-selected="someSelected"
            :sticky-header="stickyHeader"
            :resizable="resizable"
            :column-widths="columnWidths"
            :sticky-column-count="stickyColumnCount"
            @toggle-sort="toggleSort"
            @toggle-all="toggleAll"
            @resize="setColumnWidth"
            @sticky-offsets-change="stickyOffsets = $event"
          />
          <DataTableBody
            ref="bodyRef"
            :columns="visibleColumns"
            :items="items"
            :row-key="rowKey"
            :loading="loading"
            :empty-title="emptyTitle"
            :empty-description="emptyDescription"
            :status-rail-key="statusRailKey"
            :selectable="selectable"
            :selected="selected"
            :expandable="expandable"
            :editable-keys="editableKeys"
            :column-widths="columnWidths"
            :sticky-column-count="stickyColumnCount"
            :sticky-offsets="stickyOffsets"
            :group-by="groupBy"
            :default-collapsed-groups="defaultCollapsedGroups"
            @toggle-row="toggleRow"
            @cell-edit="(item, key, value) => emit('cell-edit', item, key, value)"
          >
            <template
              v-for="column in forwardedCellColumns"
              :key="column.key"
              #[`cell-${column.key}`]="slotProps"
            >
              <slot :name="`cell-${column.key}`" v-bind="slotProps" />
            </template>
            <template
              v-for="column in forwardedFooterColumns"
              :key="`footer-${column.key}`"
              #[`footer-${column.key}`]="slotProps"
            >
              <slot :name="`footer-${column.key}`" v-bind="slotProps" />
            </template>
            <template v-if="slots.empty" #empty>
              <slot name="empty" />
            </template>
            <template v-if="slots['row-detail']" #row-detail="slotProps">
              <slot name="row-detail" v-bind="slotProps" />
            </template>
          </DataTableBody>
          <tfoot
            v-if="hasFooterRow"
            class="sticky bottom-0 z-10 border-t-2 border-border bg-surface-50"
          >
            <tr>
              <td v-if="selectable" class="w-10 px-4 py-3" />
              <td v-if="expandable" class="w-8 px-2 py-3" />
              <td class="px-4 py-3 text-sm font-semibold text-ink-900">Total</td>
              <td
                v-for="column in visibleColumns.slice(1)"
                :key="column.key"
                class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-ink-900"
                :class="alignClass(column.align)"
              >
                <template v-if="footerTotals?.[column.key] !== undefined">
                  <slot :name="`footer-${column.key}`" :value="footerTotals[column.key]" :items="items">
                    {{ footerTotals[column.key] }}
                  </slot>
                </template>
                <template v-else-if="column.footer">
                  <slot :name="`footer-${column.key}`" :value="grandTotals[column.key]" :items="items">
                    {{ formatAggregate(grandTotals[column.key]) }}
                  </slot>
                </template>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <DataTableFooter
      v-if="total !== undefined || links"
      :total="total"
      :from="from"
      :to="to"
      :links="links"
      :per-page-options="perPageOptions"
      v-model:per-page="perPage"
    />
  </div>
</template>
