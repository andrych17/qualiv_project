<!-- ponytail: Accounting §3G new fixed asset. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  assetGroups: Array<{ value: number; label: string }>
  accounts: Array<{ value: number; label: string }>
}>()

const methodOptions = [
  { value: 'straight_line', label: 'Straight-line' },
  { value: 'declining_balance', label: 'Declining-balance' },
]

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
  company_id: props.selectedCompanyId,
  asset_group_id: null as number | null,
  asset_no: '',
  name: '',
  vendor_partner_id: null as number | null,
  acquisition_date: today,
  acquisition_cost: null as number | null,
  asset_gl_account_id: null as number | null,
  accumulated_depreciation_gl_account_id: null as number | null,
  depreciation_expense_gl_account_id: null as number | null,
  commercial_useful_life_months: null as number | null,
  commercial_method: 'straight_line',
  commercial_declining_rate: '',
  fiscal_method: 'straight_line',
})

const submit = () => form.transform((data) => ({
  ...data,
  acquisition_cost: Number(data.acquisition_cost) || 0,
  commercial_useful_life_months: Number(data.commercial_useful_life_months) || 0,
  commercial_declining_rate: data.commercial_method === 'declining_balance' && data.commercial_declining_rate !== '' ? Number(data.commercial_declining_rate) : null,
})).post(route('accounting.fixed-assets.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Fixed Asset" description="Register a capitalized fixed asset, configure its GL accounts and depreciation parameters." />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
          <FormSearchableSelect v-model="form.asset_group_id" name="asset_group_id" label="Asset Group" :options="assetGroups" :error="form.errors.asset_group_id" required />
          <FormInput v-model="form.asset_no" name="asset_no" label="Asset Number / Tag" placeholder="e.g. FA-2026-001" :error="form.errors.asset_no" required />
          <FormInput v-model="form.name" name="name" label="Asset Name" placeholder="e.g. MacBook Pro M3 Max" :error="form.errors.name" required />
          <FormAsyncSearchableSelect v-model="form.vendor_partner_id" name="vendor_partner_id" label="Vendor (Optional)" api-entity="crm_partner" placeholder="Search vendor..." :error="form.errors.vendor_partner_id" />
          <FormInput v-model="form.acquisition_date" name="acquisition_date" type="date" label="Acquisition Date" :error="form.errors.acquisition_date" required />
          <FormCurrencyInput v-model="form.acquisition_cost" name="acquisition_cost" label="Acquisition Cost" :error="form.errors.acquisition_cost" required />
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-3 text-sm font-semibold text-ink-900">GL Account Mapping</div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormSearchableSelect v-model="form.asset_gl_account_id" name="asset_gl_account_id" label="Asset Account" :options="accounts" :error="form.errors.asset_gl_account_id" required />
            <FormSearchableSelect v-model="form.accumulated_depreciation_gl_account_id" name="accumulated_depreciation_gl_account_id" label="Accumulated Depreciation" :options="accounts" :error="form.errors.accumulated_depreciation_gl_account_id" required />
            <FormSearchableSelect v-model="form.depreciation_expense_gl_account_id" name="depreciation_expense_gl_account_id" label="Depreciation Expense" :options="accounts" :error="form.errors.depreciation_expense_gl_account_id" required />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-3 text-sm font-semibold text-ink-900">Commercial Depreciation</div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormNumberInput v-model="form.commercial_useful_life_months" name="commercial_useful_life_months" label="Useful Life (Months)" suffix="months" :error="form.errors.commercial_useful_life_months" required />
            <FormSearchableSelect v-model="form.commercial_method" name="commercial_method" label="Depreciation Method" :options="methodOptions" :error="form.errors.commercial_method" required />
            <FormInput
              v-if="form.commercial_method === 'declining_balance'"
              v-model="form.commercial_declining_rate"
              name="commercial_declining_rate"
              type="number"
              step="0.0001"
              label="Declining-Balance Rate (annual, e.g. 0.50)"
              :error="form.errors.commercial_declining_rate"
              required
            />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-2 text-sm font-semibold text-ink-900">Fiscal Depreciation (Tax)</div>
          <p class="mb-3 text-xs text-ink-600">Rate and useful life come from the asset group. Election of fiscal straight-line vs declining balance is specified here.</p>
          <FormSearchableSelect v-model="form.fiscal_method" name="fiscal_method" label="Fiscal Method" :options="methodOptions" :error="form.errors.fiscal_method" required class="max-w-xs" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.fixed-assets.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Asset</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
