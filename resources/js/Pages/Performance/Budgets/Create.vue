<!-- ponytail: New Budget (§3B) — always starts draft; lines are freely editable until submitted. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import BudgetLineListInput, { type BudgetLineRow } from '@/Components/performance/BudgetLineListInput.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  periods: Array<{ id: number; label: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
}>()

const form = useForm({
  name: '',
  subject_type: 'company' as 'company' | 'org_unit' | 'employee',
  subject_id: null as number | null,
  fiscal_year: new Date().getFullYear(),
  fiscal_quarter: null as number | null,
  owner_id: null as number | null,
  notes: '',
  lines: [] as BudgetLineRow[],
})

watch(() => form.subject_type, () => { form.subject_id = null })

const submit = () => form.post(route('performance.budgets.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Budget" description="Plan spend per category and period for a subject and fiscal year." />

    <PerformanceSubNav active="budgets" class="mt-6" />

    <Panel class="mt-6 max-w-3xl">
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

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.fiscal_year" name="fiscal_year" type="number" label="Fiscal year" :error="form.errors.fiscal_year" required />
          <FormSelect
            v-model="form.fiscal_quarter"
            name="fiscal_quarter"
            label="Quarter (optional — leave blank for the whole year)"
            placeholder="Whole year"
            :options="[1, 2, 3, 4].map((q) => ({ label: `Q${q}`, value: q }))"
            :error="form.errors.fiscal_quarter"
          />
        </div>

        <FormTextarea v-model="form.notes" name="notes" label="Notes" :error="form.errors.notes" />

        <BudgetLineListInput v-model="form.lines" :periods="periods" />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('performance.budgets.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save draft</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
