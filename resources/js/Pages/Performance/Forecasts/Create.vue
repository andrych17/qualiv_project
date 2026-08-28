<!-- ponytail: New Forecast (§3H) — link to exactly one of a Budget or a KPI; subject is picked only
     for the KPI path since a Budget-linked forecast always inherits the Budget's own subject. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import ForecastLineListInput, { type ForecastLineRow } from '@/Components/performance/ForecastLineListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  budgets: Array<{ id: number; name: string; fiscal_year: number }>
  kpis: Array<{ id: number; name: string }>
  periods: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
}>()

const form = useForm({
  linkType: 'kpi' as 'budget' | 'kpi',
  budget_id: null as number | null,
  kpi_id: null as number | null,
  subject_type: 'company' as 'company' | 'org_unit' | 'employee',
  subject_id: null as number | null,
  period_id: null as number | null,
  notes: '',
  lines: [] as ForecastLineRow[],
})

watch(() => form.linkType, (type) => {
  if (type === 'budget') { form.kpi_id = null } else { form.budget_id = null }
})
watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.transform(({ linkType: _linkType, ...rest }) => rest).post(route('performance.forecasts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Forecast" description="Project a trajectory against a Budget's total or a KPI target." />

    <PerformanceSubNav active="forecasts" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormRadioGroup
          v-model="form.linkType"
          name="linkType"
          label="Link to"
          inline
          :options="[
            { label: 'A Budget', value: 'budget' },
            { label: 'A KPI target', value: 'kpi' },
          ]"
        />

        <FormSelect
          v-if="form.linkType === 'budget'"
          v-model="form.budget_id"
          name="budget_id"
          label="Budget"
          placeholder="Select a budget…"
          :options="budgets.map((b) => ({ label: `${b.name} (${b.fiscal_year})`, value: b.id }))"
          :error="form.errors.budget_id"
          required
        />

        <template v-else>
          <FormSelect
            v-model="form.kpi_id"
            name="kpi_id"
            label="KPI"
            placeholder="Select a KPI…"
            :options="kpis.map((k) => ({ label: k.name, value: k.id }))"
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
        </template>

        <FormSelect
          v-model="form.period_id"
          name="period_id"
          label="Horizon (overall period this forecast covers)"
          placeholder="Select a period…"
          :options="periods.map((p) => ({ label: p.label, value: p.id }))"
          :error="form.errors.period_id"
          required
        />

        <FormTextarea v-model="form.notes" name="notes" label="Notes" :error="form.errors.notes" />

        <ForecastLineListInput v-model="form.lines" :periods="periods" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.forecasts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save forecast</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
