<!-- ponytail: single data row + its optional expand-detail row, extracted from DataTableBody
     so flat and grouped rendering reuse the exact same row markup instead of duplicating it. -->
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
    item: Record<string, any>
    columns: Column[]
    statusRailKey?: string
    selectable?: boolean
    isSelected?: boolean
    expandable?: boolean
    editableKeys?: string[]
    columnWidths?: Record<string, number>
    stickyColumnCount?: number
    stickyOffsets?: number[]
    colspan: number
  }>(),
  { editableKeys: () => [], stickyOffsets: () => [] },
)

const emit = defineEmits<{
  'toggle-row': []
  'cell-edit': [key: string, value: string]
}>()

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const railClass = () => {
  if (!props.statusRailKey) return ''
  const status = String(props.item[props.statusRailKey] ?? '').toLowerCase()
  const map: Record<string, string> = {
    open: 'border-l-[3px] border-l-signal-info',
    pending: 'border-l-[3px] border-l-signal-warning',
    closed: 'border-l-[3px] border-l-signal-success',
    active: 'border-l-[3px] border-l-signal-success',
    overdue: 'border-l-[3px] border-l-signal-danger',
    rejected: 'border-l-[3px] border-l-signal-danger',
    // CRM SLA state (§3E) — svc_cases rail reflects SLA standing, not workflow status.
    breached: 'border-l-[3px] border-l-signal-danger',
    due_soon: 'border-l-[3px] border-l-signal-warning',
    on_track: 'border-l-[3px] border-l-signal-success',
    // DESIGN.md's own canonical Status Rail words (§3A WNE Dashboard is the first caller to
    // pass these directly rather than a business-specific state) — kept alongside the
    // business-specific keys above rather than replacing them.
    danger: 'border-l-[3px] border-l-signal-danger',
    warning: 'border-l-[3px] border-l-signal-warning',
    success: 'border-l-[3px] border-l-signal-success',
    info: 'border-l-[3px] border-l-signal-info',
    neutral: 'border-l-[3px] border-l-border',
  }
  return map[status] ?? 'border-l-[3px] border-l-border'
}

// --- Expand ---
const expanded = ref(false)

// --- Inline editor ---
const editingKey = ref<string | null>(null)
const editingValue = ref('')

const isEditable = (column: Column) => props.editableKeys?.includes(column.key)

const startEdit = (column: Column) => {
  if (!isEditable(column)) return
  editingKey.value = column.key
  editingValue.value = String(props.item[column.key] ?? '')
}

const commitEdit = (column: Column) => {
  if (editingKey.value === null) return
  editingKey.value = null
  if (String(props.item[column.key] ?? '') !== editingValue.value) {
    emit('cell-edit', column.key, editingValue.value)
  }
}

const cancelEdit = () => {
  editingKey.value = null
}

const stickyStyle = (idx: number) => {
  if (idx >= (props.stickyColumnCount ?? 0)) return {}
  const leadCount = (props.selectable ? 1 : 0) + (props.expandable ? 1 : 0)
  return { left: (props.stickyOffsets?.[leadCount + idx] ?? 0) + 'px' }
}
</script>

<template>
  <tr class="hover:bg-surface-50" :class="railClass()">
    <td v-if="selectable" class="w-10 px-4 py-3" :class="{ 'sticky z-10 bg-surface-0': (stickyColumnCount ?? 0) > 0 }" style="left: 0">
      <input
        type="checkbox"
        class="rounded border-border text-accent focus:ring-accent"
        :checked="isSelected"
        @change="emit('toggle-row')"
      />
    </td>
    <td v-if="expandable" class="w-8 px-2 py-3">
      <button type="button" class="text-ink-600 hover:text-ink-900" @click="expanded = !expanded">
        <ChevronRight class="h-4 w-4 transition-transform" :class="{ 'rotate-90': expanded }" />
      </button>
    </td>
    <td
      v-for="(column, idx) in columns"
      :key="column.key"
      class="whitespace-nowrap px-4 py-3 text-sm text-ink-900"
      :class="[alignClass(column.align), idx < (stickyColumnCount ?? 0) ? 'sticky z-10 bg-surface-0' : '']"
      :style="{ ...(columnWidths?.[column.key] ? { width: columnWidths[column.key] + 'px' } : {}), ...stickyStyle(idx) }"
      @dblclick="startEdit(column)"
    >
      <input
        v-if="editingKey === column.key"
        v-model="editingValue"
        type="text"
        autofocus
        class="w-full rounded border border-accent px-1 py-0.5 text-sm"
        @keyup.enter="commitEdit(column)"
        @keyup.escape="cancelEdit"
        @blur="commitEdit(column)"
      />
      <template v-else>
        <slot :name="`cell-${column.key}`" :item="item" :value="item[column.key]">
          {{ item[column.key] }}
        </slot>
        <span v-if="isEditable(column)" class="ml-1 text-[10px] text-ink-600/60">✎</span>
      </template>
    </td>
  </tr>
  <tr v-if="expandable && expanded">
    <td :colspan="colspan" class="bg-surface-50 px-4 py-3">
      <slot name="row-detail" :item="item" />
    </td>
  </tr>
</template>
