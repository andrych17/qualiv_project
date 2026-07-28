<!-- ponytail: thead only — sort headers, select-all, resize handles, sticky (frozen) columns. -->
<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-vue-next'
import type { SortState } from '@/Composables/useDataTable'

type Column = {
  key: string
  label: string
  sortable?: boolean
  sortKey?: string
  align?: 'left' | 'center' | 'right'
}

const props = defineProps<{
  columns: Column[]
  sort: SortState
  selectable?: boolean
  expandable?: boolean
  allSelected?: boolean
  someSelected?: boolean
  stickyHeader?: boolean
  resizable?: boolean
  columnWidths?: Record<string, number>
  /** Number of leading visible columns to freeze (after the selection/expand columns). */
  stickyColumnCount?: number
}>()

const emit = defineEmits<{
  'toggle-sort': [column: Column]
  'toggle-all': []
  resize: [key: string, width: number]
  'sticky-offsets-change': [offsets: number[]]
}>()

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const sortKeyOf = (column: Column) => column.sortKey ?? column.key

const sortIcon = (column: Column) => {
  if (props.sort?.key !== sortKeyOf(column)) return ChevronsUpDown
  return props.sort.direction === 'asc' ? ChevronUp : ChevronDown
}

// --- Resize ---
const resizing = ref<{ key: string; startX: number; startWidth: number } | null>(null)

const startResize = (column: Column, event: MouseEvent) => {
  if (!props.resizable) return
  const th = (event.target as HTMLElement).closest('th')
  resizing.value = { key: column.key, startX: event.clientX, startWidth: th?.offsetWidth ?? 160 }
  window.addEventListener('mousemove', onResizeMove)
  window.addEventListener('mouseup', stopResize)
}

const onResizeMove = (event: MouseEvent) => {
  if (!resizing.value) return
  const delta = event.clientX - resizing.value.startX
  emit('resize', resizing.value.key, resizing.value.startWidth + delta)
}

const stopResize = () => {
  resizing.value = null
  window.removeEventListener('mousemove', onResizeMove)
  window.removeEventListener('mouseup', stopResize)
}

onUnmounted(stopResize)

// --- Sticky columns: measure rendered <th> widths so Body can align matching <td> offsets ---
const thRefs = ref<Record<string, HTMLElement | null>>({})

const setThRef = (key: string, el: Element | null) => {
  thRefs.value[key] = el as HTMLElement | null
}

const recomputeStickyOffsets = () => {
  const count = props.stickyColumnCount ?? 0
  if (count <= 0) {
    emit('sticky-offsets-change', [])
    return
  }
  let offset = 0
  const offsets: number[] = []
  const leadKeys = [
    ...(props.selectable ? ['__select'] : []),
    ...(props.expandable ? ['__expand'] : []),
    ...props.columns.slice(0, count).map((c) => c.key),
  ]
  for (const key of leadKeys) {
    offsets.push(offset)
    offset += thRefs.value[key]?.offsetWidth ?? 40
  }
  emit('sticky-offsets-change', offsets)
}

onMounted(() => nextTick(recomputeStickyOffsets))
watch(() => [props.columns, props.stickyColumnCount, props.columnWidths], () => nextTick(recomputeStickyOffsets), { deep: true })

defineExpose({ recomputeStickyOffsets })
</script>

<template>
  <thead class="bg-surface-50" :class="{ 'sticky top-0 z-10': stickyHeader }">
    <tr>
      <th
        v-if="selectable"
        :ref="(el) => setThRef('__select', el as Element | null)"
        class="w-10 px-4 py-3"
        :class="{ 'sticky z-20 bg-surface-50': (stickyColumnCount ?? 0) > 0 }"
        style="left: 0"
      >
        <input
          type="checkbox"
          class="rounded border-border text-accent focus:ring-accent"
          :checked="allSelected"
          :indeterminate="someSelected"
          @change="emit('toggle-all')"
        />
      </th>
      <th
        v-if="expandable"
        :ref="(el) => setThRef('__expand', el as Element | null)"
        class="w-8 px-2 py-3"
      />
      <th
        v-for="(column, idx) in columns"
        :key="column.key"
        :ref="(el) => setThRef(column.key, el as Element | null)"
        class="relative px-4 py-3 text-xs font-semibold uppercase tracking-wide text-ink-600"
        :class="[
          alignClass(column.align),
          column.sortable ? 'cursor-pointer select-none hover:text-ink-900' : '',
          idx < (stickyColumnCount ?? 0) ? 'sticky z-20 bg-surface-50' : '',
        ]"
        :style="columnWidths?.[column.key] ? { width: columnWidths[column.key] + 'px' } : {}"
        @click="emit('toggle-sort', column)"
      >
        <span class="inline-flex items-center gap-1" :class="{ 'flex-row-reverse': column.align === 'right' }">
          {{ column.label }}
          <component :is="sortIcon(column)" v-if="column.sortable" class="h-3.5 w-3.5" />
        </span>
        <span
          v-if="resizable"
          class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-accent/40"
          @click.stop
          @mousedown.stop="startResize(column, $event)"
        />
      </th>
    </tr>
  </thead>
</template>
