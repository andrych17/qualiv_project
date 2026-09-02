<!-- ponytail: Edit Work Center (MES_SPECS.md §3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  workCenter: {
    id: number
    code: string
    name: string
    area_line: string | null
    type: string
  }
}>()

const typeOptions = [
  { value: 'discrete', label: 'Discrete' },
  { value: 'process', label: 'Process' },
]

const form = useForm({
  code: props.workCenter.code,
  name: props.workCenter.name,
  area_line: props.workCenter.area_line ?? '',
  type: props.workCenter.type,
})

const submit = () => form.put(route('mes.workCenters.update', props.workCenter.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Work Center" :description="workCenter.code" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.area_line" name="area_line" label="Area / Line" placeholder="e.g. Assembly Line A" :error="form.errors.area_line" />
          <FormSelect v-model="form.type" name="type" label="Type" :options="typeOptions" :error="form.errors.type" required />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.workCenters.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Work Center</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
