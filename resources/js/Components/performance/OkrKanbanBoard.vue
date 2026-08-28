<!-- ponytail: OKR Objective board (§3E). Same HTML5 drag/drop mechanics as
     Components/kanban/KanbanBoard.vue, but a dedicated card (progress bar, key-result count,
     subject) doesn't fit the Issues-shaped KanbanItem type — same reasoning CRM's
     LeadKanbanBoard already documents for staying module-local. -->
<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

export interface ObjectiveItem {
  id: number
  objective_text: string
  subject_label: string
  status: string
  parent_okr_id: number | null
  parent_text: string | null
  key_results_count: number
  progress: number | null
}

const COLUMNS = [
  { key: 'on_track', label: 'On Track' },
  { key: 'at_risk', label: 'At Risk' },
  { key: 'off_track', label: 'Off Track' },
  { key: 'completed', label: 'Completed' },
]

const props = defineProps<{
  items: ObjectiveItem[]
  itemHref: (item: ObjectiveItem) => string
}>()

const emit = defineEmits<{
  'status-change': [objectiveId: number, status: string]
}>()

const itemsByStatus = computed(() => {
  const map: Record<string, ObjectiveItem[]> = {}
  for (const col of COLUMNS) {
    map[col.key] = props.items.filter((i) => i.status === col.key)
  }
  return map
})

const onDragStart = (event: DragEvent, id: number) => {
  event.dataTransfer?.setData('text/plain', String(id))
}

const onDrop = (event: DragEvent, status: string) => {
  const id = Number(event.dataTransfer?.getData('text/plain'))
  if (!id) return
  emit('status-change', id, status)
}

const progressBarClass = (progress: number | null) => {
  if (progress === null) return 'bg-ink-600/30'
  if (progress >= 100) return 'bg-signal-success'
  if (progress >= 50) return 'bg-signal-info'
  return 'bg-signal-warning'
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
    <div
      v-for="column in COLUMNS"
      :key="column.key"
      class="rounded-md border border-border bg-surface-50 p-3"
      @dragover.prevent
      @drop="onDrop($event, column.key)"
    >
      <div class="mb-3 flex items-center justify-between">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">{{ column.label }}</p>
        <span class="rounded-full bg-surface-0 px-2 py-0.5 text-[11px] font-medium text-ink-600">
          {{ (itemsByStatus[column.key] ?? []).length }}
        </span>
      </div>

      <div class="space-y-2">
        <Link
          v-for="item in itemsByStatus[column.key]"
          :key="item.id"
          :href="itemHref(item)"
          draggable="true"
          class="block cursor-grab rounded-md border-l-2 border-l-border bg-surface-0 p-3 shadow-sm transition hover:shadow active:cursor-grabbing"
          @dragstart="onDragStart($event, item.id)"
        >
          <p class="text-sm font-medium text-ink-900">{{ item.objective_text }}</p>
          <p class="text-xs text-ink-600">{{ item.subject_label }}</p>
          <p v-if="item.parent_text" class="mt-1 text-xs text-ink-600/70">Aligned to: {{ item.parent_text }}</p>

          <div class="mt-2">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-surface-50">
              <div class="h-full rounded-full" :class="progressBarClass(item.progress)" :style="{ width: `${Math.min(Math.max(item.progress ?? 0, 0), 100)}%` }" />
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-ink-600">
              <span>{{ item.progress === null ? 'No key results' : `${Math.round(item.progress)}%` }}</span>
              <span>{{ item.key_results_count }} KR{{ item.key_results_count === 1 ? '' : 's' }}</span>
            </div>
          </div>
        </Link>
        <p v-if="(itemsByStatus[column.key] ?? []).length === 0" class="text-xs text-ink-600">
          No objectives in {{ column.label }} yet.
        </p>
      </div>
    </div>
  </div>
</template>
