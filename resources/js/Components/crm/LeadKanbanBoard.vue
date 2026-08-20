<!-- ponytail: Lead pipeline board (§3D). Same HTML5 drag/drop mechanics as
     Components/kanban/KanbanBoard.vue, but a dedicated card — a Lead's DESIGN.md-specified
     card content (owner chip + estimated value + next action date, no priority/attachments)
     doesn't fit the Issues-shaped KanbanItem type, so this stays CRM-local rather than
     forcing a shared type to grow fields only Leads use.
     Dropping on Converted/Disqualified never completes silently (DESIGN.md: "a drop that
     would violate a hard business rule opens the relevant inline form instead") — those
     columns emit request-convert/request-disqualify instead of stage-change. -->
<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { User } from 'lucide-vue-next'

export interface LeadItem {
  id: number
  name: string
  company_name: string | null
  stage: string
  source_name: string | null
  owner_id: number | null
  owner_name: string | null
  estimated_value: string | number | null
  estimated_value_formatted: string | null
  next_action_at: string | null
  next_action_formatted: string | null
  is_overdue: boolean
  disqualify_reason: string | null
}

const DIRECT_STAGES = ['new', 'contacted', 'qualified']

const COLUMNS = [
  { key: 'new', label: 'New' },
  { key: 'contacted', label: 'Contacted' },
  { key: 'qualified', label: 'Qualified' },
  { key: 'converted', label: 'Converted' },
  { key: 'disqualified', label: 'Disqualified' },
]

const props = defineProps<{
  items: LeadItem[]
  itemHref: (item: LeadItem) => string
}>()

const emit = defineEmits<{
  'stage-change': [leadId: number, stage: string]
  'request-convert': [leadId: number]
  'request-disqualify': [leadId: number]
}>()

const itemsByStage = computed(() => {
  const map: Record<string, LeadItem[]> = {}
  for (const col of COLUMNS) {
    map[col.key] = props.items.filter((i) => i.stage === col.key)
  }
  return map
})

const columnTotal = (key: string) => {
  const sum = (itemsByStage.value[key] ?? []).reduce((acc, i) => acc + (Number(i.estimated_value) || 0), 0)
  return sum > 0 ? sum.toLocaleString() : null
}

const onDragStart = (event: DragEvent, leadId: number) => {
  event.dataTransfer?.setData('text/plain', String(leadId))
}

const onDrop = (event: DragEvent, stage: string) => {
  const leadId = Number(event.dataTransfer?.getData('text/plain'))
  if (!leadId) return

  if (DIRECT_STAGES.includes(stage)) {
    emit('stage-change', leadId, stage)
  } else if (stage === 'converted') {
    emit('request-convert', leadId)
  } else if (stage === 'disqualified') {
    emit('request-disqualify', leadId)
  }
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
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
          {{ (itemsByStage[column.key] ?? []).length }}
        </span>
      </div>
      <p v-if="columnTotal(column.key)" class="mb-2 font-serif text-sm text-ink-900">
        {{ columnTotal(column.key) }}
      </p>

      <div class="space-y-2">
        <Link
          v-for="item in itemsByStage[column.key]"
          :key="item.id"
          :href="itemHref(item)"
          draggable="true"
          class="block cursor-grab rounded-md border-l-2 bg-surface-0 p-3 shadow-sm transition hover:shadow active:cursor-grabbing"
          :class="item.is_overdue ? 'border-l-signal-danger' : 'border-l-border'"
          @dragstart="onDragStart($event, item.id)"
        >
          <p class="text-sm font-medium text-ink-900">{{ item.name }}</p>
          <p v-if="item.company_name" class="text-xs text-ink-600">{{ item.company_name }}</p>
          <div class="mt-2 flex items-center justify-between gap-2 text-xs">
            <span v-if="item.owner_name" class="flex items-center gap-1 text-ink-600" :title="item.owner_name">
              <User class="h-3 w-3" />
              {{ item.owner_name }}
            </span>
            <span v-else class="text-ink-600/60">Unassigned</span>
            <span v-if="item.estimated_value_formatted" class="font-mono text-ink-900">
              {{ item.estimated_value_formatted }}
            </span>
          </div>
          <p
            v-if="item.next_action_formatted"
            class="mt-1 text-xs"
            :class="item.is_overdue ? 'font-semibold text-signal-danger' : 'text-ink-600'"
          >
            {{ item.is_overdue ? 'Overdue — ' : 'Next: ' }}{{ item.next_action_formatted }}
          </p>
        </Link>
        <p v-if="(itemsByStage[column.key] ?? []).length === 0" class="text-xs text-ink-600">
          No leads in {{ column.label }} yet.
        </p>
      </div>
    </div>
  </div>
</template>
