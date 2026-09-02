<!-- ponytail: Edit Machine (MES_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  machine: {
    id: number
    work_center_id: number
    code: string
    name: string
    status: string
  }
  workCenters: Array<{ value: number; label: string }>
}>()

const statusOptions = [
  { value: 'running', label: 'Running' },
  { value: 'idle', label: 'Idle' },
  { value: 'down', label: 'Down' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'setup', label: 'Setup' },
  { value: 'waiting_material', label: 'Waiting Material' },
  { value: 'waiting_operator', label: 'Waiting Operator' },
  { value: 'waiting_qc', label: 'Waiting QC' },
]

const form = useForm({
  work_center_id: props.machine.work_center_id,
  code: props.machine.code,
  name: props.machine.name,
  status: props.machine.status,
})

const submit = () => form.put(route('mes.machines.update', props.machine.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Machine" :description="machine.code" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect v-model="form.work_center_id" name="work_center_id" label="Work Center" :options="workCenters" :error="form.errors.work_center_id" required />

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        </div>

        <FormSelect v-model="form.status" name="status" label="Status" :options="statusOptions" :error="form.errors.status" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.machines.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Machine</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
