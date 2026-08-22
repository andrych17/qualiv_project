<!-- ponytail: Create Resource (§3D) — Panel + design-system inputs, mirrors Schedule Tasks Create -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import WorkingHoursInput, { type WorkingHourRow } from '@/Components/schedule/WorkingHoursInput.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  resourceTypes: Array<{ id: number; name: string }>
}>()

const form = useForm({
  resource_type_id: null as number | null,
  name: '',
  location_notes: '',
  capacity: null as number | null,
  working_hours: [] as WorkingHourRow[],
})

const submit = () => form.post(route('schedule.resources.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add resource" description="A bookable room, vehicle, piece of equipment, or staff-as-resource." />

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
        <FormInput v-model="form.name" name="name" label="Name" placeholder="e.g. Conference Room A" :error="form.errors.name" required />
        <FormTextarea v-model="form.location_notes" name="location_notes" label="Location / notes" :error="form.errors.location_notes" />
        <FormInput
          v-model="form.capacity"
          name="capacity"
          type="number"
          label="Capacity"
          placeholder="Optional — informational only"
          :error="form.errors.capacity"
        />

        <WorkingHoursInput v-model="form.working_hours" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('schedule.resources.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save resource</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
