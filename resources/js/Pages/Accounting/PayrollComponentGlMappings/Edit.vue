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
  { value: 'earning', label: 'Earning (debits an expense account)' },
  { value: 'deduction', label: 'Deduction (credits a payable account)' },
  { value: 'employer_cost', label: 'Employer cost (debits an expense AND credits a payable)' },
]

const form = useForm({
  component_label: props.mapping.component_label,
  component_type: props.mapping.component_type,
  gl_account_id: props.mapping.gl_account_id,
  payable_account_id: props.mapping.payable_account_id,
})

const isEmployerCost = computed(() => form.component_type === 'employer_cost')
const glAccountLabel = computed(() => (form.component_type === 'deduction' ? 'Payable account' : 'Expense account'))

const submit = () => form.put(route('accounting.payroll-component-gl-mappings.update', props.mapping.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit mapping — ${mapping.component_code}`" description="component_code can't be changed here — it's the join key a future Payroll engine will match against." />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <div class="text-sm font-medium text-ink-900">Component code</div>
          <div class="rounded-sm border border-border bg-surface-50 px-3 py-2 text-sm text-ink-700">{{ mapping.component_code }}</div>
        </div>
        <FormInput v-model="form.component_label" name="component_label" label="Label" :error="form.errors.component_label" required />

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
            :href="route('accounting.payroll-component-gl-mappings.index', { company_id: mapping.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
