<!-- ponytail: tbody only — cell renderer (slots), row actions (via #cell-actions slot upstream), inline editor, expandable row. -->
<script setup lang="ts">
import { ref } from 'vue'
import { ChevronRight } from 'lucide-vue-next'

type Column = {
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
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
  }>(),
  { rowKey: 'id', selected: () => [], editableKeys: () => [], stickyOffsets: () => [] },
)

const emit = defineEmits<{
  'toggle-row': [item: Record<string, any>]
  'cell-edit': [item: Record<string, any>, key: string, value: string]
}>()

const rowId = (item: Record<string, any>) => item[props.rowKey]
const isSelected = (item: Record<string, any>) => props.selected.includes(rowId(item))

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

const colspan = () =>
  props.columns.length + (props.selectable ? 1 : 0) + (props.expandable ? 1 : 0)

// --- Expandable rows ---
const expandedIds = ref<Set<string | number>>(new Set())
const isExpanded = (item: Record<string, any>) => expandedIds.value.has(rowId(item))
const toggleExpanded = (item: Record<string, any>) => {
  const id = rowId(item)
  const next = new Set(expandedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expandedIds.value = next
}

// --- Inline editor ---
const editingCell = ref<{ id: string | number; key: string } | null>(null)
const editingValue = ref('')

const isEditable = (column: Column) => props.editableKeys?.includes(column.key)

const isEditingCell = (item: Record<string, any>, column: Column) =>
  editingCell.value !== null && editingCell.value.id === rowId(item) && editingCell.value.key === column.key

const startEdit = (item: Record<string, any>, column: Column) => {
  if (!isEditable(column)) return
  editingCell.value = { id: rowId(item), key: column.key }
  editingValue.value = String(item[column.key] ?? '')
}

const commitEdit = (item: Record<string, any>, column: Column) => {
  if (!editingCell.value) return
  editingCell.value = null
  if (String(item[column.key] ?? '') !== editingValue.value) {
    emit('cell-edit', item, column.key, editingValue.value)
  }
}

const cancelEdit = () => {
  editingCell.value = null
}

const stickyStyle = (idx: number) => {
  if (idx >= (props.stickyColumnCount ?? 0)) return {}
  const leadCount = (props.selectable ? 1 : 0) + (props.expandable ? 1 : 0)
  return { left: (props.stickyOffsets?.[leadCount + idx] ?? 0) + 'px' }
}
</script>

<template>
  <tbody class="divide-y divide-border bg-surface-0">
    <tr v-if="loading">
      <td :colspan="colspan()" class="px-4 py-8 text-center text-sm text-ink-600">
        Loading…
      </td>
    </tr>

    <tr v-else-if="items.length === 0">
      <td :colspan="colspan()" class="px-4 py-10 text-center">
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

    <template v-else v-for="item in items" :key="rowId(item)">
      <tr class="hover:bg-surface-50" :class="railClass(item)">
        <td v-if="selectable" class="w-10 px-4 py-3" :class="{ 'sticky z-10 bg-surface-0': (stickyColumnCount ?? 0) > 0 }" style="left: 0">
          <input
            type="checkbox"
            class="rounded border-border text-accent focus:ring-accent"
            :checked="isSelected(item)"
            @change="emit('toggle-row', item)"
          />
        </td>
        <td v-if="expandable" class="w-8 px-2 py-3">
          <button type="button" class="text-ink-600 hover:text-ink-900" @click="toggleExpanded(item)">
            <ChevronRight class="h-4 w-4 transition-transform" :class="{ 'rotate-90': isExpanded(item) }" />
          </button>
        </td>
        <td
          v-for="(column, idx) in columns"
          :key="column.key"
          class="whitespace-nowrap px-4 py-3 text-sm text-ink-900"
          :class="[alignClass(column.align), idx < (stickyColumnCount ?? 0) ? 'sticky z-10 bg-surface-0' : '']"
          :style="{ ...(columnWidths?.[column.key] ? { width: columnWidths[column.key] + 'px' } : {}), ...stickyStyle(idx) }"
          @dblclick="startEdit(item, column)"
        >
          <input
            v-if="isEditingCell(item, column)"
            v-model="editingValue"
            type="text"
            autofocus
            class="w-full rounded border border-accent px-1 py-0.5 text-sm"
            @keyup.enter="commitEdit(item, column)"
            @keyup.escape="cancelEdit"
            @blur="commitEdit(item, column)"
          />
          <template v-else>
            <slot :name="`cell-${column.key}`" :item="item" :value="item[column.key]">
              {{ item[column.key] }}
            </slot>
            <span v-if="isEditable(column)" class="ml-1 text-[10px] text-ink-600/60">✎</span>
          </template>
        </td>
      </tr>
      <tr v-if="expandable && isExpanded(item)">
        <td :colspan="colspan()" class="bg-surface-50 px-4 py-3">
          <slot name="row-detail" :item="item" />
        </td>
      </tr>
    </template>
  </tbody>
</template>
