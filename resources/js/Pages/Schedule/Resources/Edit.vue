<!-- ponytail: Edit Resource (§3D) — mirrors Create, adds is_active + deactivate -->
<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WorkingHoursInput, { type WorkingHourRow } from '@/Components/schedule/WorkingHoursInput.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  resource: {
    id: number
    resource_type_id: number | null
    name: string
    location_notes: string | null
    capacity: number | null
    is_active: boolean
    working_hours: WorkingHourRow[]
  }
  resourceTypes: Array<{ id: number; name: string }>
}>()

const form = useForm({
  resource_type_id: props.resource.resource_type_id,
  name: props.resource.name,
  location_notes: props.resource.location_notes ?? '',
  capacity: props.resource.capacity,
  is_active: props.resource.is_active,
  working_hours: [...props.resource.working_hours],
})

const submit = () => form.put(route('schedule.resources.update', props.resource.id))

const { confirm } = useConfirm()
const confirmDeactivate = () => {
  confirm({
    title: `Deactivate ${props.resource.name}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('schedule.resources.destroy', props.resource.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="resource.name" description="Resource details">
      <template #actions>
        <StatusBadge :status="resource.is_active ? 'active' : 'inactive'" />
      </template>
    </PageHeader>

    <ScheduleSubNav active="resources" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.resource_type_id"
          name="resource_type_id"
          label="Type"
          :options="resourceTypes.map((t) => ({ label: t.name, value: t.id }))"
          :error="form.errors.resource_type_id"
          required
        />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormTextarea v-model="form.location_notes" name="location_notes" label="Location / notes" :error="form.errors.location_notes" />
        <FormInput
          v-model="form.capacity"
          name="capacity"
          type="number"
          label="Capacity"
          placeholder="Optional — informational only"
          :error="form.errors.capacity"
        />
        <FormSwitch v-model="form.is_active" label="Active" description="Inactive resources can't be booked on new events." />

        <WorkingHoursInput v-model="form.working_hours" />

        <div class="flex items-center justify-between border-t border-border pt-4">
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmDeactivate"
          >
            Deactivate resource
          </button>
          <div class="flex items-center gap-3">
            <Link
              :href="route('schedule.resources.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Cancel
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save resource</PrimaryButton>
          </div>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
