<!-- ponytail: Edit KPI Definition (§3C) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  kpi: {
    id: number
    name: string
    unit: string
    direction: 'higher_is_better' | 'lower_is_better'
    perspective_id: number | null
    description: string | null
    is_active: boolean
  }
  perspectives: Array<{ id: number; name: string }>
}>()

const form = useForm({
  name: props.kpi.name,
  unit: props.kpi.unit as 'number' | 'percent' | 'currency' | 'ratio',
  direction: props.kpi.direction,
  perspective_id: props.kpi.perspective_id,
  description: props.kpi.description ?? '',
  is_active: props.kpi.is_active,
})

const submit = () => form.put(route('performance.kpiDefinitions.update', props.kpi.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit KPI" />

    <PerformanceSubNav active="kpiDefinitions" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />

        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.unit"
            name="unit"
            label="Unit"
            :options="[
              { label: 'Number', value: 'number' },
              { label: 'Percent', value: 'percent' },
              { label: 'Currency', value: 'currency' },
              { label: 'Ratio', value: 'ratio' },
            ]"
            :error="form.errors.unit"
            required
          />
          <FormSelect
            v-model="form.direction"
            name="direction"
            label="Direction"
            :options="[
              { label: 'Higher is better', value: 'higher_is_better' },
              { label: 'Lower is better', value: 'lower_is_better' },
            ]"
            :error="form.errors.direction"
            required
          />
        </div>

        <FormSelect
          v-model="form.perspective_id"
          name="perspective_id"
          label="Perspective"
          placeholder="Unassigned"
          :options="perspectives.map((p) => ({ label: p.name, value: p.id }))"
          :error="form.errors.perspective_id"
        />

        <FormTextarea v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <FormSwitch v-model="form.is_active" label="Active" description="Deactivating blocks new target assignments — historical targets/values stay visible (§3C)." />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.kpiDefinitions.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update KPI</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
