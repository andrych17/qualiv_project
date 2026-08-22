<!-- ponytail: §3A "click a time slot → inline mini-form" — same Modal.vue + useForm recipe
     as CRM's ConvertLeadModal.vue. Posts to the dashboard's own quick-create endpoints
     (not the full Tasks/Events store routes) so the redirect lands back on the calendar,
     not the Tasks/Events index. -->
<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  show: boolean
  defaultDatetime: string
  resources: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  close: []
}>()

const form = useForm({
  title: '',
  type: 'task' as 'task' | 'event',
  datetime: props.defaultDatetime,
  resource_id: null as number | null,
})

watch(() => props.show, (show) => {
  if (show) {
    form.reset()
    form.datetime = props.defaultDatetime
  }
})

const addOneHour = (dt: string): string => {
  const d = new Date(dt)
  d.setHours(d.getHours() + 1)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const submit = () => {
  if (form.type === 'task') {
    form.transform((data) => ({
      title: data.title,
      due_at: data.datetime,
      priority: 'normal',
    })).post(route('schedule.dashboard.quickCreateTask'), {
      onSuccess: () => emit('close'),
    })
  } else {
    form.transform((data) => ({
      title: data.title,
      start_at: data.datetime,
      end_at: addOneHour(data.datetime),
      resource_ids: data.resource_id ? [data.resource_id] : [],
    })).post(route('schedule.dashboard.quickCreateEvent'), {
      onSuccess: () => emit('close'),
    })
  }
}
</script>

<template>
  <Modal :show="show" max-width="sm" @close="emit('close')">
    <div class="p-6">
      <h2 class="font-serif text-lg font-semibold text-ink-900">Quick add</h2>
      <p class="mt-1 text-sm text-ink-600">Add a task or event without leaving the calendar.</p>

      <form class="mt-4 space-y-4" @submit.prevent="submit">
        <FormRadioGroup
          v-model="form.type"
          name="type"
          label="Type"
          inline
          :options="[
            { label: 'Task', value: 'task' },
            { label: 'Event', value: 'event' },
          ]"
        />
        <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
        <FormInput
          v-model="form.datetime"
          name="datetime"
          type="datetime-local"
          :label="form.type === 'task' ? 'Due' : 'Start'"
          required
        />
        <FormSelect
          v-if="form.type === 'event'"
          v-model="form.resource_id"
          name="resource_id"
          label="Resource"
          placeholder="None"
          :options="resources.map((r) => ({ label: r.name, value: r.id }))"
        />

        <!-- Errors come back keyed by the transformed payload's field names
             (due_at/start_at/end_at/resource_ids), not this form's own field
             names — shown generically rather than guessed per-field. -->
        <p v-for="(message, field) in (form.errors as Record<string, string>)" :key="field" class="text-sm text-signal-danger">{{ message }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <button
            type="button"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="emit('close')"
          >
            Cancel
          </button>
          <PrimaryButton type="submit" :disabled="form.processing">Create</PrimaryButton>
        </div>
      </form>
    </div>
  </Modal>
</template>
