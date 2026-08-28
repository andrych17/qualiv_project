<!-- ponytail: Edit Scorecard (§3F Builder) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import ScorecardItemListInput, { type ScorecardItemRow } from '@/Components/performance/ScorecardItemListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  perspectives: Array<{ id: number; name: string }>
  kpis: Array<{ id: number; name: string }>
  okrObjectives: Array<{ id: number; objective_text: string }>
  periods: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
  scorecard: {
    id: number
    name: string
    subject_type: 'company' | 'org_unit' | 'employee'
    subject_id: number | null
    period_id: number
    items: Array<{ perspective_id: number; kpi_id: number | null; okr_id: number | null; weight: number }>
  }
}>()

const form = useForm({
  name: props.scorecard.name,
  subject_type: props.scorecard.subject_type,
  subject_id: props.scorecard.subject_id,
  period_id: props.scorecard.period_id,
  items: props.scorecard.items.map((i): ScorecardItemRow => ({
    perspective_id: i.perspective_id,
    linkType: i.kpi_id !== null ? 'kpi' : 'okr',
    kpi_id: i.kpi_id,
    okr_id: i.okr_id,
    weight: i.weight,
  })),
})

watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.transform(({ items, ...rest }) => ({
  ...rest,
  items: items.map(({ linkType: _linkType, ...item }) => item),
})).put(route('performance.scorecards.update', props.scorecard.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Scorecard" />

    <PerformanceSubNav active="scorecards" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />

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

        <ScorecardItemListInput v-model="form.items" :perspectives="perspectives" :kpis="kpis" :okr-objectives="okrObjectives" />
        <p v-if="form.errors.items" class="text-sm text-signal-danger">{{ form.errors.items }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.scorecards.show', scorecard.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
