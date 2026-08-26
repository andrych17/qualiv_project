<!-- ponytail: Accounting §3S — edit a payroll component → GL account mapping.
     component_code is fixed after creation (it's the join key a future Payroll engine will
     match against) — only the label/type/accounts can change. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

type Option = { value: number; label: string }

const props = defineProps<{
  mapping: {
    id: number
    company_id: number
    component_code: string
    component_label: string
    component_type: 'earning' | 'deduction' | 'employer_cost'
    gl_account_id: number
    payable_account_id: number | null
  }
  accounts: Option[]
}>()

const typeOptions = [
  { value: 'earning', label: 'Earning (Debits an expense account)' },
  { value: 'deduction', label: 'Deduction (Credits a payable account)' },
  { value: 'employer_cost', label: 'Employer Cost (Debits expense & credits payable)' },
]

const form = useForm({
  component_label: props.mapping.component_label,
  component_type: props.mapping.component_type,
  gl_account_id: props.mapping.gl_account_id,
  payable_account_id: props.mapping.payable_account_id,
})

const isEmployerCost = computed(() => form.component_type === 'employer_cost')
const glAccountLabel = computed(() => (form.component_type === 'deduction' ? 'Payable Account' : 'Expense Account'))

const submit = () => form.put(route('accounting.payroll-component-gl-mappings.update', props.mapping.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit Mapping — ${mapping.component_code}`" description="Configure GL accounts for this payroll salary/benefit component." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <div class="text-xs font-semibold text-ink-600">Component Code</div>
          <div class="rounded-md border border-border bg-surface-50 px-3 py-2 text-sm font-mono font-bold text-ink-900">{{ mapping.component_code }}</div>
        </div>
        <FormInput v-model="form.component_label" name="component_label" label="Component Label" :error="form.errors.component_label" required />

        <FormSearchableSelect v-model="form.component_type" name="component_type" label="Component Type" :options="typeOptions" :clearable="false" :error="form.errors.component_type" required />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" :label="glAccountLabel" :options="accounts" :error="form.errors.gl_account_id" required />
          <FormSearchableSelect
            v-model="form.payable_account_id"
            name="payable_account_id"
            label="Payable Account"
            :placeholder="isEmployerCost ? 'Required for employer-cost components' : 'Not used for this type'"
            :options="accounts"
            :error="form.errors.payable_account_id"
            :disabled="!isEmployerCost"
          />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.payroll-component-gl-mappings.index', { company_id: mapping.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
