<!-- ponytail: tbody orchestrator — flat or grouped rows (Excel Group/Outline + per-group subtotal),
     delegates a single data row + its expand-detail row to DataTableRow so both modes share one
     row template instead of duplicating markup. -->
<script setup lang="ts">
import { computed, ref, useSlots, watch } from 'vue'
import { ChevronRight } from 'lucide-vue-next'
import DataTableRow from '@/Components/tables/DataTableRow.vue'
import { aggregateColumn, formatAggregate, type FooterAggregate } from '@/Composables/useDataTable'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

type Column = {
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
  /** Built-in aggregate shown in this column's group-subtotal / grand-total cell. */
  footer?: FooterAggregate
}

const props = withDefaults(
  defineProps<{
    columns: Column[]
    items: Record<string, any>[]
    rowKey?: string
    loading?: boolean
    emptyTitle?: string
    emptyDescription?: string
    statusRailKey?: string
    selectable?: boolean
    selected?: Array<string | number>
    expandable?: boolean
    editableKeys?: string[]
    columnWidths?: Record<string, number>
    stickyColumnCount?: number
    stickyOffsets?: number[]
    /** Groups rows like Excel's Group/Outline: a column key, or a resolver returning {key,label}. */
    groupBy?: string | ((item: Record<string, any>) => { key: string | number; label: string })
    /** Groups start collapsed instead of expanded. */
    defaultCollapsedGroups?: boolean
  }>(),
  { rowKey: 'id', selected: () => [], editableKeys: () => [], stickyOffsets: () => [] },
)

const emit = defineEmits<{
  'toggle-row': [item: Record<string, any>]
  'cell-edit': [item: Record<string, any>, key: string, value: string]
}>()

const slots = useSlots()
// Only forward per-column cell slots the host page actually provided — see DataTable.vue's
// forwardedCellColumns for why (an always-registered but empty slot shadows the row's own fallback).
const forwardedCellColumns = computed(() => props.columns.filter((c) => !!slots[`cell-${c.key}`]))

const rowId = (item: Record<string, any>) => item[props.rowKey]
const isSelected = (item: Record<string, any>) => props.selected.includes(rowId(item))

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const colspan = () =>
  props.columns.length + (props.selectable ? 1 : 0) + (props.expandable ? 1 : 0)

// --- Grouping ---
type Group = { key: string | number; label: string; items: Record<string, any>[] }

const resolveGroup = (item: Record<string, any>): { key: string | number; label: string } => {
  if (typeof props.groupBy === 'function') return props.groupBy(item)
  const key = item[props.groupBy as string]
  return { key, label: String(key ?? '—') }
}

const groups = computed<Group[] | null>(() => {
  if (!props.groupBy) return null
  const map = new Map<string | number, Group>()
  for (const item of props.items) {
    const { key, label } = resolveGroup(item)
    const existing = map.get(key)
    if (existing) {
      existing.items.push(item)
    } else {
      map.set(key, { key, label, items: [item] })
    }
  }
  return [...map.values()]
})

const collapsedGroupKeys = ref<Set<string | number>>(new Set())
// Seed the default-collapsed state once groups first appear; don't re-seed on later item
// reloads (a debounced search/sort refresh) or it would stomp on the user's own toggles.
let seededDefaultCollapse = false
watch(
  groups,
  (list) => {
    if (!list || seededDefaultCollapse || !props.defaultCollapsedGroups) return
    seededDefaultCollapse = true
    collapsedGroupKeys.value = new Set(list.map((g) => g.key))
  },
  { immediate: true },
)

const isGroupCollapsed = (key: string | number) => collapsedGroupKeys.value.has(key)
const toggleGroup = (key: string | number) => {
  const next = new Set(collapsedGroupKeys.value)
  next.has(key) ? next.delete(key) : next.add(key)
  collapsedGroupKeys.value = next
}
const expandAllGroups = () => {
  collapsedGroupKeys.value = new Set()
}
const collapseAllGroups = () => {
  collapsedGroupKeys.value = new Set((groups.value ?? []).map((g) => g.key))
}
defineExpose({ expandAllGroups, collapseAllGroups })

type RenderRow = { type: 'group'; group: Group } | { type: 'row'; item: Record<string, any> }

const visibleRows = computed<RenderRow[]>(() => {
  if (!groups.value) return props.items.map((item) => ({ type: 'row', item }))
  const rows: RenderRow[] = []
  for (const group of groups.value) {
    rows.push({ type: 'group', group })
    if (!isGroupCollapsed(group.key)) {
      for (const item of group.items) rows.push({ type: 'row', item })
    }
  }
  return rows
})
</script>

<template>
  <tbody class="divide-y divide-border bg-surface-0">
    <tr v-if="loading">
      <td :colspan="colspan()" class="px-4 py-8 text-center text-sm text-ink-600">
        {{ t('common.loading') }}
      </td>
    </tr>

    <tr v-else-if="items.length === 0">
      <td :colspan="colspan()" class="px-4 py-10 text-center">
        <slot name="empty">
          <div class="space-y-1">
            <p class="text-sm font-medium text-ink-900">
              {{ emptyTitle ?? t('table.empty_title') }}
            </p>
            <p class="text-sm text-ink-600">
              {{ emptyDescription ?? t('table.empty_desc') }}
            </p>
          </div>
        </slot>
      </td>
    </tr>

    <template
      v-else
      v-for="row in visibleRows"
      :key="row.type === 'group' ? `group-${row.group.key}` : rowId(row.item)"
    >
      <tr
        v-if="row.type === 'group'"
        class="cursor-pointer bg-surface-50 hover:bg-surface-100"
        @click="toggleGroup(row.group.key)"
      >
        <td v-if="selectable" class="w-10 px-4 py-2" />
        <td v-if="expandable" class="w-8 px-2 py-2" />
        <td class="px-4 py-2 text-sm font-semibold text-ink-900">
          <span class="inline-flex items-center gap-1.5">
            <ChevronRight
              class="h-4 w-4 transition-transform"
              :class="{ 'rotate-90': !isGroupCollapsed(row.group.key) }"
            />
            {{ row.group.label }}
            <span class="font-normal text-ink-600">({{ row.group.items.length }})</span>
          </span>
        </td>
        <td
          v-for="column in columns.slice(1)"
          :key="column.key"
          class="whitespace-nowrap px-4 py-2 text-sm font-semibold text-ink-900"
          :class="alignClass(column.align)"
        >
          <slot
            v-if="column.footer"
            :name="`footer-${column.key}`"
            :value="aggregateColumn(row.group.items, column.key, column.footer)"
            :items="row.group.items"
          >
            {{ formatAggregate(aggregateColumn(row.group.items, column.key, column.footer)) }}
          </slot>
        </td>
      </tr>

      <DataTableRow
        v-else
        :item="row.item"
        :columns="columns"
        :status-rail-key="statusRailKey"
        :selectable="selectable"
        :is-selected="isSelected(row.item)"
        :expandable="expandable"
        :editable-keys="editableKeys"
        :column-widths="columnWidths"
        :sticky-column-count="stickyColumnCount"
        :sticky-offsets="stickyOffsets"
        :colspan="colspan()"
        @toggle-row="emit('toggle-row', row.item)"
        @cell-edit="(key, value) => emit('cell-edit', row.item, key, value)"
      >
        <template
          v-for="column in forwardedCellColumns"
          :key="column.key"
          #[`cell-${column.key}`]="slotProps"
        >
          <slot :name="`cell-${column.key}`" v-bind="slotProps" />
        </template>
        <template v-if="slots['row-detail']" #row-detail="slotProps">
          <slot name="row-detail" v-bind="slotProps" />
        </template>
      </DataTableRow>
    </template>
  </tbody>
</template>
