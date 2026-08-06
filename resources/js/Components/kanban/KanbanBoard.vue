<!-- ponytail: Kanban board — HTML5 drag/drop columns, no extra dependency.
     Host owns status/assignee PATCH; board only groups, sorts, and emits events. -->
<script setup lang="ts">
import { computed } from 'vue'
import KanbanColumn from '@/Components/kanban/KanbanColumn.vue'
import {
  sortKanbanItems,
  type KanbanColumnDef,
  type KanbanItem,
  type KanbanUserOption,
} from '@/Components/kanban/types'

const props = defineProps<{
  columns: KanbanColumnDef[]
  items: KanbanItem[]
  userOptions: KanbanUserOption[]
  itemHref: (item: KanbanItem) => string
}>()

const emit = defineEmits<{
  'status-change': [itemId: number, status: string]
  'assignee-change': [itemId: number, value: string | number | null]
}>()

const itemsByStatus = computed(() => {
  const map: Record<string, KanbanItem[]> = {}
  for (const col of props.columns) {
    map[col.key] = sortKanbanItems(props.items.filter((i) => i.status === col.key))
  }
  return map
})

const onDragStart = (event: DragEvent, itemId: number) => {
  event.dataTransfer?.setData('text/plain', String(itemId))
}

const onDrop = (event: DragEvent, status: string) => {
  const itemId = Number(event.dataTransfer?.getData('text/plain'))
  if (!itemId) return
  emit('status-change', itemId, status)
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
    <KanbanColumn
      v-for="column in columns"
      :key="column.key"
      :status-key="column.key"
      :label="column.label"
      :items="itemsByStatus[column.key] ?? []"
      :user-options="userOptions"
      :item-href="itemHref"
      @drop="onDrop"
      @dragstart="onDragStart"
      @assignee-change="(id, value) => emit('assignee-change', id, value)"
    />
  </div>
</template>
