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
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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
    <PageHeader :title="t('schedule.add_resource')" :description="t('schedule.add_resource_desc')" />

    <ScheduleSubNav active="resources" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.resource_type_id"
          name="resource_type_id"
          :label="t('schedule.resource_type')"
          :options="resourceTypes.map((tItem) => ({ label: tItem.name, value: tItem.id }))"
          :error="form.errors.resource_type_id"
          required
        />
        <FormInput
          v-model="form.name"
          name="name"
          :label="t('schedule.resource_name')"
          :placeholder="t('schedule.resource_name')"
          :error="form.errors.name"
          required
        />
        <FormTextarea
          v-model="form.location_notes"
          name="location_notes"
          :label="t('schedule.location_notes')"
          :error="form.errors.location_notes"
        />
        <FormInput
          v-model="form.capacity"
          name="capacity"
          type="number"
          :label="t('schedule.capacity')"
          :placeholder="t('schedule.capacity_placeholder')"
          :error="form.errors.capacity"
        />

        <WorkingHoursInput v-model="form.working_hours" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('schedule.resources.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ t('common.cancel') }}
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">{{ t('schedule.save_resource') }}</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
