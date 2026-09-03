<!-- ponytail: Edit Station (MES_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  station: {
    id: number
    work_center_id: number | null
    machine_id: number | null
    code: string
    name: string
  }
  workCenters: Array<{ value: number; label: string }>
  machines: Array<{ value: number; label: string }>
}>()

const form = useForm({
  work_center_id: props.station.work_center_id,
  machine_id: props.station.machine_id,
  code: props.station.code,
  name: props.station.name,
})

const submit = () => form.put(route('mes.stations.update', props.station.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Station" :description="station.code" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect v-model="form.work_center_id" name="work_center_id" label="Work Center" :options="workCenters" :error="form.errors.work_center_id" />
          <FormSelect v-model="form.machine_id" name="machine_id" label="Machine" :options="machines" :error="form.errors.machine_id" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.stations.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Station</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
