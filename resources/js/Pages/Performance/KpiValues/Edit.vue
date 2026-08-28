<!-- ponytail: Edit KPI Actual (§3D) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  value: {
    id: number
    kpi_id: number
    subject_type: 'company' | 'org_unit' | 'employee'
    subject_id: number | null
    period_id: number
    actual_value: number
  }
  kpis: Array<{ id: number; name: string; unit: string }>
  periods: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
}>()

const form = useForm({
  kpi_id: props.value.kpi_id,
  subject_type: props.value.subject_type,
  subject_id: props.value.subject_id,
  period_id: props.value.period_id,
  actual_value: props.value.actual_value,
})

watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.put(route('performance.kpiValues.update', props.value.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Actual" />

    <PerformanceSubNav active="kpiValues" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.kpi_id"
          name="kpi_id"
          label="KPI"
          placeholder="Select a KPI…"
          :options="kpis.map((k) => ({ label: `${k.name} (${k.unit})`, value: k.id }))"
          :error="form.errors.kpi_id"
          required
        />

        <FormRadioGroup
          v-model="form.subject_type"
          name="subject_type"
          label="Subject level"
          inline
          :options="[
            { label: 'Company', value: 'company' },
            { label: 'Org Unit', value: 'org_unit' },
            { label: 'Employee', value: 'employee' },
          ]"
        />

        <FormSelect
          v-if="form.subject_type === 'org_unit'"
          v-model="form.subject_id"
          name="subject_id"
          label="Org Unit"
          placeholder="Select an org unit…"
          :options="orgUnits.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.subject_id"
          required
        />
        <FormSelect
          v-else-if="form.subject_type === 'employee'"
          v-model="form.subject_id"
          name="subject_id"
          label="Employee"
          placeholder="Select an employee…"
          :options="employees.map((e) => ({ label: `${e.employee_no} — ${e.full_name}`, value: e.id }))"
          :error="form.errors.subject_id"
          required
        />

        <FormSelect
          v-model="form.period_id"
          name="period_id"
          label="Period"
          placeholder="Select a period…"
          :options="periods.map((p) => ({ label: p.label, value: p.id }))"
          :error="form.errors.period_id"
          required
        />

        <FormNumberInput v-model="form.actual_value" name="actual_value" label="Actual value" :error="form.errors.actual_value" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.kpiValues.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update actual</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
