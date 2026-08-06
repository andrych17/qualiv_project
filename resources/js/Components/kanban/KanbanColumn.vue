<!-- ponytail: Drop-zone column — header count + stacked KanbanCard list. -->
<script setup lang="ts">
import KanbanCard from '@/Components/kanban/KanbanCard.vue'
import type { KanbanItem, KanbanUserOption } from '@/Components/kanban/types'

defineProps<{
  statusKey: string
  label: string
  items: KanbanItem[]
  userOptions: KanbanUserOption[]
  /** Resolve card href from item (page owns routes). */
  itemHref: (item: KanbanItem) => string
}>()

const emit = defineEmits<{
  drop: [event: DragEvent, statusKey: string]
  dragstart: [event: DragEvent, itemId: number]
  'assignee-change': [itemId: number, value: string | number | null]
}>()
</script>

<template>
  <div
    class="rounded-md border border-border bg-surface-50 p-3"
    @dragover.prevent
    @drop="emit('drop', $event, statusKey)"
  >
    <p class="mb-3 flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-ink-600">
      {{ label }}
      <span class="rounded-full bg-surface-0 px-2 py-0.5 text-[11px] font-medium text-ink-600">
        {{ items.length }}
      </span>
    </p>
    <div class="space-y-2">
      <KanbanCard
        v-for="item in items"
        :key="item.id"
        :item="item"
        :href="itemHref(item)"
        :user-options="userOptions"
        @dragstart="(e, id) => emit('dragstart', e, id)"
        @assignee-change="(id, value) => emit('assignee-change', id, value)"
      />
      <p v-if="items.length === 0" class="text-xs text-ink-600">No issues.</p>
    </div>
  </div>
</template>
