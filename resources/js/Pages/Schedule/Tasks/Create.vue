<!-- ponytail: Create Task (§3B) — Panel + design-system inputs, mirrors CRM Contacts Create -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CheckboxMultiSelect from '@/Components/schedule/CheckboxMultiSelect.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

const props = defineProps<{
  owners: Array<{ id: number; name: string }>
}>()

const form = useForm({
  title: '',
  description: '',
  due_at: '',
  priority: 'normal',
  owner_id: null as number | null,
  subject_type: '',
  subject_id: null as number | null,
  recurrence_rule: '',
  watcher_ids: [] as number[],
})

const submit = () => form.post(route('schedule.tasks.store'))
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('schedule.add_task')" :description="t('schedule.add_task_desc')" />

    <ScheduleSubNav active="tasks" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.title" name="title" :label="t('schedule.task_title')" :error="form.errors.title" required />
        <FormTextarea v-model="form.description" name="description" :label="t('schedule.task_desc')" :error="form.errors.description" />
        <FormInput
          v-model="form.due_at"
          name="due_at"
          type="datetime-local"
          :label="t('schedule.due_date')"
          :error="form.errors.due_at"
          required
        />
        <FormSelect
          v-model="form.priority"
          name="priority"
          :label="t('schedule.priority')"
          :options="[
            { label: t('schedule.priority_low'), value: 'low' },
            { label: t('schedule.priority_normal'), value: 'normal' },
            { label: t('schedule.priority_high'), value: 'high' },
          ]"
          :error="form.errors.priority"
        />
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

        <CheckboxMultiSelect v-model="form.watcher_ids" :options="owners" :label="t('schedule.watchers')" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('schedule.tasks.index')"
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
