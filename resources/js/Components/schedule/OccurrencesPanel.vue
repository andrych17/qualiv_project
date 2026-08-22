<!-- ponytail: §3F "handle this occurrence only edits/cancellations" — the concrete UI
     surface for that, ahead of §3A's full calendar view. Shared by Task/Event Edit. -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

export interface OccurrenceRow {
  original_date: string
  start: string
  end: string
  status: string
}

const props = withDefaults(defineProps<{
  occurrences: OccurrenceRow[]
  itemId: number
  skipRoute: string
  rescheduleRoute: string
  restoreRoute: string
  showEnd?: boolean
}>(), {
  showEnd: true,
})

const { confirm } = useConfirm()

const editingDate = ref<string | null>(null)
const rescheduleForm = useForm({ original_occurrence_date: '', start_at: '', end_at: '' })

const startEdit = (occ: OccurrenceRow) => {
  editingDate.value = occ.original_date
  rescheduleForm.clearErrors()
  rescheduleForm.original_occurrence_date = occ.original_date
  rescheduleForm.start_at = occ.start
  rescheduleForm.end_at = occ.end
}

const cancelEdit = () => {
  editingDate.value = null
}

// Keep end_at valid as start_at moves — for a Task (showEnd false) they're always
// equal; for an Event, only correct end_at forward when the edit would otherwise
// put it before the new start (server also enforces this either way).
watch(() => rescheduleForm.start_at, (newStart) => {
  if (!editingDate.value) return

  if (!props.showEnd) {
    rescheduleForm.end_at = newStart
  } else if (rescheduleForm.end_at < newStart) {
    rescheduleForm.end_at = newStart
  }
})

const saveReschedule = () => {
  rescheduleForm.post(route(props.rescheduleRoute, props.itemId), {
    preserveScroll: true,
    onSuccess: () => { editingDate.value = null },
  })
}

const skip = (occ: OccurrenceRow) => {
  confirm({
    title: `Skip the ${occ.original_date} occurrence?`,
    variant: 'destructive',
    confirmText: 'Skip',
    onConfirm: () => router.post(
      route(props.skipRoute, props.itemId),
      { original_occurrence_date: occ.original_date },
      { preserveScroll: true },
    ),
  })
}

const restore = (occ: OccurrenceRow) => {
  router.post(
    route(props.restoreRoute, props.itemId),
    { original_occurrence_date: occ.original_date },
    { preserveScroll: true },
  )
}
</script>

<template>
  <div class="space-y-2">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Upcoming occurrences</p>
    <p v-if="occurrences.length === 0" class="text-sm text-ink-600">No upcoming occurrences in the next 90 days.</p>
    <ul v-else class="divide-y divide-border rounded-md border border-border">
      <li v-for="occ in occurrences" :key="occ.original_date" class="p-3 text-sm">
        <div v-if="editingDate !== occ.original_date" class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="font-medium text-ink-900">{{ occ.start.replace('T', ' ') }}</span>
            <span v-if="showEnd" class="text-ink-600">– {{ occ.end.split('T')[1] }}</span>
            <StatusBadge v-if="occ.status !== 'scheduled'" :status="occ.status" />
          </div>
          <div class="flex items-center gap-3">
            <button
              v-if="occ.status !== 'scheduled'"
              type="button"
              class="text-sm font-medium text-accent hover:underline"
              @click="restore(occ)"
            >
              Restore
            </button>
            <template v-else>
              <button type="button" class="text-sm font-medium text-accent hover:underline" @click="startEdit(occ)">
                Reschedule
              </button>
              <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="skip(occ)">
                Skip
              </button>
            </template>
          </div>
        </div>
        <div v-else class="space-y-2">
          <div class="flex items-center gap-2">
            <input
              v-model="rescheduleForm.start_at"
              type="datetime-local"
              class="rounded-md border border-border bg-white px-2 py-1 text-sm shadow-sm outline-none focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
            />
            <template v-if="showEnd">
              <span class="text-ink-600">to</span>
              <input
                v-model="rescheduleForm.end_at"
                type="datetime-local"
                class="rounded-md border border-border bg-white px-2 py-1 text-sm shadow-sm outline-none focus:border-ink-900 focus:ring-2 focus:ring-ink-900/10"
              />
            </template>
          </div>
          <p v-for="(message, field) in (rescheduleForm.errors as Record<string, string>)" :key="field" class="text-sm text-signal-danger">{{ message }}</p>
          <div class="flex gap-3">
            <button
              type="button"
              class="text-sm font-semibold text-accent hover:underline disabled:opacity-50"
              :disabled="rescheduleForm.processing"
              @click="saveReschedule"
            >
              Save
            </button>
            <button type="button" class="text-sm text-ink-600 hover:underline" @click="cancelEdit">Cancel</button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
