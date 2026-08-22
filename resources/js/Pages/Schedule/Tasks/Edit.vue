<!-- ponytail: Edit Task (§3B) — mirrors Create, adds status + delete -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CheckboxMultiSelect from '@/Components/schedule/CheckboxMultiSelect.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import OccurrencesPanel, { type OccurrenceRow } from '@/Components/schedule/OccurrencesPanel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  task: {
    id: number
    title: string
    description: string | null
    due_at: string | null
    priority: string | null
    status: string
    owner_id: number | null
    subject_type: string | null
    subject_id: number | null
    recurrence_rule: string | null
    watcher_ids: number[]
  }
  owners: Array<{ id: number; name: string }>
  occurrences: OccurrenceRow[]
}>()

const form = useForm({
  title: props.task.title,
  description: props.task.description ?? '',
  due_at: props.task.due_at ?? '',
  priority: props.task.priority ?? 'normal',
  status: props.task.status,
  owner_id: props.task.owner_id,
  subject_type: props.task.subject_type ?? '',
  subject_id: props.task.subject_id,
  recurrence_rule: props.task.recurrence_rule ?? '',
  watcher_ids: [...props.task.watcher_ids],
})

const submit = () => form.put(route('schedule.tasks.update', props.task.id))

const { confirm } = useConfirm()
const confirmDelete = () => {
  confirm({
    title: `Delete "${props.task.title}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('schedule.tasks.destroy', props.task.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="task.title" description="Task details">
      <template #actions>
        <StatusBadge :status="task.status" />
      </template>
    </PageHeader>

    <ScheduleSubNav active="tasks" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
        <FormTextarea v-model="form.description" name="description" label="Description" :error="form.errors.description" />
        <FormInput
          v-model="form.due_at"
          name="due_at"
          type="datetime-local"
          label="Due"
          :error="form.errors.due_at"
          required
        />
        <FormSelect
          v-model="form.priority"
          name="priority"
          label="Priority"
          :options="[
            { label: 'Low', value: 'low' },
            { label: 'Normal', value: 'normal' },
            { label: 'High', value: 'high' },
          ]"
          :error="form.errors.priority"
        />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Open', value: 'open' },
            { label: 'In progress', value: 'in_progress' },
            { label: 'Done', value: 'done' },
            { label: 'Cancelled', value: 'cancelled' },
          ]"
          :error="form.errors.status"
        />
        <FormSelect
          v-model="form.owner_id"
          name="owner_id"
          label="Owner"
          placeholder="Unassigned"
          :options="owners.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.owner_id"
        />
        <FormInput
          v-model="form.recurrence_rule"
          name="recurrence_rule"
          label="Recurrence rule"
          placeholder="e.g. FREQ=WEEKLY;BYDAY=MO;COUNT=10 (optional)"
          :error="form.errors.recurrence_rule"
        />

        <CheckboxMultiSelect v-model="form.watcher_ids" :options="owners" label="Watchers" />

        <OccurrencesPanel
          v-if="task.recurrence_rule"
          :occurrences="occurrences"
          :item-id="task.id"
          skip-route="schedule.tasks.occurrences.skip"
          reschedule-route="schedule.tasks.occurrences.reschedule"
          restore-route="schedule.tasks.occurrences.restore"
          :show-end="false"
        />

        <div class="flex items-center justify-between border-t border-border pt-4">
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmDelete"
          >
            Delete task
          </button>
          <div class="flex items-center gap-3">
            <Link
              :href="route('schedule.tasks.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Cancel
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save task</PrimaryButton>
          </div>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
