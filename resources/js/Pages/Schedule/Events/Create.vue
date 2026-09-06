<!-- ponytail: Create Event (§3C) — Panel + design-system inputs, mirrors Schedule Tasks Create -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import CheckboxMultiSelect from '@/Components/schedule/CheckboxMultiSelect.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const props = defineProps<{
  owners: Array<{ id: number; name: string }>
  resources: Array<{ id: number; name: string }>
  conferenceProviders: Array<{ id: number; code: string; name: string }>
}>()

const form = useForm({
  title: '',
  description: '',
  start_at: '',
  end_at: '',
  all_day: false,
  location: '',
  owner_id: null as number | null,
  subject_type: '',
  subject_id: null as number | null,
  recurrence_rule: '',
  attendee_ids: [] as number[],
  resource_ids: [] as number[],
  conference_provider_code: '',
  conference_manual_url: '',
})

const addConference = ref(false)

watch(addConference, (enabled) => {
  if (!enabled) {
    form.conference_provider_code = ''
    form.conference_manual_url = ''
  }
})

const submit = () => form.post(route('schedule.events.store'))
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('schedule.add_event')" :description="t('schedule.add_event_desc')" />

    <ScheduleSubNav active="events" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.title" name="title" :label="t('schedule.event_title')" :error="form.errors.title" required />
        <FormTextarea v-model="form.description" name="description" :label="t('schedule.event_desc')" :error="form.errors.description" />
        <div class="grid grid-cols-2 gap-4">
          <FormInput
            v-model="form.start_at"
            name="start_at"
            type="datetime-local"
            :label="t('schedule.start_time')"
            :error="form.errors.start_at"
            required
          />
          <FormInput
            v-model="form.end_at"
            name="end_at"
            type="datetime-local"
            :label="t('schedule.end_time')"
            :error="form.errors.end_at"
            required
          />
        </div>
        <FormSwitch v-model="form.all_day" :label="t('schedule.all_day')" :description="t('schedule.all_day_desc')" />
        <FormInput v-model="form.location" name="location" :label="t('schedule.location')" :error="form.errors.location" />
        <FormSelect
          v-model="form.owner_id"
          name="owner_id"
          :label="t('schedule.owner')"
          :options="owners.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.owner_id"
        />
        <FormInput
          v-model="form.recurrence_rule"
          name="recurrence_rule"
          :label="t('schedule.recurrence')"
          placeholder="e.g. FREQ=WEEKLY;BYDAY=MO;COUNT=10 (optional)"
          :error="form.errors.recurrence_rule"
        />

        <CheckboxMultiSelect v-model="form.attendee_ids" :options="owners" :label="t('schedule.attendees')" />
        <CheckboxMultiSelect
          v-model="form.resource_ids"
          :options="resources"
          :label="t('schedule.booked_resources')"
          :empty-text="t('schedule.no_resources')"
        />
        <p v-if="form.errors.resource_ids" class="text-sm text-signal-danger">{{ form.errors.resource_ids }}</p>

        <FormSwitch v-model="addConference" :label="t('schedule.conference_link')" />
        <template v-if="addConference">
          <FormSelect
            v-model="form.conference_provider_code"
            name="conference_provider_code"
            label="Provider"
            :options="conferenceProviders.map((p) => ({ label: p.name, value: p.code }))"
            :error="form.errors.conference_provider_code"
          />
          <FormInput
            v-if="form.conference_provider_code === 'manual'"
            v-model="form.conference_manual_url"
            name="conference_manual_url"
            label="Join URL"
            placeholder="https://…"
            :error="form.errors.conference_manual_url"
          />
        </template>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('schedule.events.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ t('common.cancel') }}
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">{{ t('common.save') }}</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
