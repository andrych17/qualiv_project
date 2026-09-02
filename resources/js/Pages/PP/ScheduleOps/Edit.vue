<!-- ponytail: Edit Schedule Operation (PP_SPECS.md §3H) — resource/date/sequence only; status moves through Commit/Release on the index page. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ScheduleOpForm {
  id: number
  plan_number: string | null
  seq: number
  resource_type: string | null
  resource_ref_id: number | null
  planned_start: string
  planned_end: string
  status: string
}

const props = defineProps<{ op: ScheduleOpForm }>()

const resourceTypeOptions = [
  { value: 'mes_work_center', label: 'Work Center' },
  { value: 'mes_machine', label: 'Machine' },
  { value: 'mes_station', label: 'Station' },
]

const form = useForm({
  seq: props.op.seq,
  resource_type: props.op.resource_type,
  resource_ref_id: props.op.resource_ref_id,
  planned_start: props.op.planned_start,
  planned_end: props.op.planned_end,
})

const submit = () => form.put(route('pp.scheduleOps.update', props.op.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit Operation — ${op.plan_number}`" description="Change resource, window, or sequence (§3H). Status moves through Commit/Release on the list page." />

    <Panel class="mt-6 max-w-2xl">
      <div class="mb-4 flex items-center gap-2 text-sm text-ink-600">
        Status: <StatusBadge :status="op.status" />
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model.number="form.seq" name="seq" type="number" min="1" label="Sequence" :error="form.errors.seq" />

        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.resource_type"
            name="resource_type"
            label="Resource type"
            placeholder="Unassigned"
            :options="resourceTypeOptions"
            :error="form.errors.resource_type"
          />
          <FormInput v-model.number="form.resource_ref_id" name="resource_ref_id" type="number" label="Resource #" :error="form.errors.resource_ref_id" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.planned_start" name="planned_start" type="datetime-local" label="Start" :error="form.errors.planned_start" required />
          <FormInput v-model="form.planned_end" name="planned_end" type="datetime-local" label="End" :error="form.errors.planned_end" required />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.scheduleOps.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
