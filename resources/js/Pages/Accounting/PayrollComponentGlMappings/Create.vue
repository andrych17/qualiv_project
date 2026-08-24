<!-- ponytail: Accounting §3S — new payroll component → GL account mapping. An employer_cost
     component needs a payable account too (it both debits an expense and credits a payable
     — enforced server-side in PayrollComponentGlMappingService), shown live here. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type Option = { value: number; label: string }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: Option[]
}>()

const typeOptions = [
  { value: 'earning', label: 'Earning (debits an expense account)' },
  { value: 'deduction', label: 'Deduction (credits a payable account)' },
  { value: 'employer_cost', label: 'Employer cost (debits an expense AND credits a payable)' },
]

const form = useForm({
  company_id: props.selectedCompanyId,
  component_code: '',
  component_label: '',
  component_type: 'earning' as 'earning' | 'deduction' | 'employer_cost',
  gl_account_id: null as number | null,
  payable_account_id: null as number | null,
})

const isEmployerCost = computed(() => form.component_type === 'employer_cost')
const glAccountLabel = computed(() => (form.component_type === 'deduction' ? 'Payable account' : 'Expense account'))

const submit = () => form.transform((data) => ({ ...data, component_code: data.component_code.trim().toUpperCase() })).post(route('accounting.payroll-component-gl-mappings.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New payroll GL mapping" description="component_code should match whatever code Payroll's own engine will eventually use (e.g. PPH21, BPJS_KESEHATAN_EE) — uppercase, letters/digits/underscore only." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect
          v-model="form.company_id"
          name="company_id"
          label="Company"
          :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
          :error="form.errors.company_id"
          required
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormInput v-model="form.component_code" name="component_code" label="Component code" placeholder="e.g. PPH21" :error="form.errors.component_code" required />
          <FormInput v-model="form.component_label" name="component_label" label="Label" placeholder="e.g. PPh 21 Withheld" :error="form.errors.component_label" required />
        </div>

        <FormSearchableSelect v-model="form.component_type" name="component_type" label="Type" :options="typeOptions" :clearable="false" :error="form.errors.component_type" required />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" :label="glAccountLabel" :options="accounts" :error="form.errors.gl_account_id" required />
          <FormSearchableSelect
            v-model="form.payable_account_id"
            name="payable_account_id"
            label="Payable account"
            :placeholder="isEmployerCost ? 'Required for employer-cost components' : 'Not used for this type'"
            :options="accounts"
            :error="form.errors.payable_account_id"
            :disabled="!isEmployerCost"
          />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.payroll-component-gl-mappings.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save mapping</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
