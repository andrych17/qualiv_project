<!-- ponytail: Draggable issue card — priority, quick-assign, attachment + due meta. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import { Paperclip } from 'lucide-vue-next'
import {
  PRIORITY_CLASS,
  PRIORITY_LABEL,
  TYPE_LABEL,
  type KanbanItem,
  type KanbanUserOption,
} from '@/Components/kanban/types'

defineProps<{
  item: KanbanItem
  href: string
  userOptions: KanbanUserOption[]
}>()

const emit = defineEmits<{
  dragstart: [event: DragEvent, itemId: number]
  'assignee-change': [itemId: number, value: string | number | null]
}>()
</script>

<template>
  <Link
    :href="href"
    draggable="true"
    class="block cursor-grab rounded-md border border-border bg-surface-0 p-3 shadow-sm transition hover:shadow active:cursor-grabbing"
    @dragstart="emit('dragstart', $event, item.id)"
  >
    <p class="font-mono text-xs text-ink-600">{{ item.code }}</p>
    <p class="mt-1 text-sm font-medium text-ink-900">{{ item.title }}</p>
    <div class="mt-2 flex items-center justify-between gap-2 text-xs">
      <span class="flex items-center gap-1.5">
        <span v-if="item.type" class="rounded bg-surface-50 px-1.5 py-0.5 text-ink-600">{{ TYPE_LABEL[item.type] ?? item.type }}</span>
        <span :class="PRIORITY_CLASS[item.priority]">{{ PRIORITY_LABEL[item.priority] ?? item.priority }}</span>
      </span>
      <div class="w-32 shrink-0" draggable="false" @click.prevent.stop @mousedown.stop @dragstart.stop>
        <FormSearchableSelect
          :model-value="item.assignee_id"
          name="assignee"
          placeholder="Unassigned"
          search-placeholder="Cari user…"
          :options="userOptions"
          @update:model-value="(value) => emit('assignee-change', item.id, value)"
        />
      </div>
    </div>
    <div class="mt-1 flex items-center justify-between text-xs">
      <span v-if="item.attachments_count" class="flex items-center gap-1 text-ink-600">
        <Paperclip class="h-3 w-3" />
        {{ item.attachments_count }}
      </span>
      <p
        v-if="item.due_date_formatted"
        class="ml-auto text-xs"
        :class="item.is_overdue ? 'font-semibold text-signal-danger' : 'text-ink-600'"
      >
        {{ item.is_overdue ? 'Overdue — ' : 'Due ' }}{{ item.due_date_formatted }}
      </p>
    </div>
  </Link>
</template>
