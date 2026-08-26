<!-- ponytail: Accounting §3G edit fixed asset. -->
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
  asset: {
    id: number
    company_id: number
    asset_group_id: number
    asset_no: string
    name: string
    vendor_partner_id: number | null
    acquisition_date: string
    acquisition_cost: string
    asset_gl_account_id: number
    accumulated_depreciation_gl_account_id: number
    depreciation_expense_gl_account_id: number
    commercial_useful_life_months: number
    commercial_method: string
    commercial_declining_rate: string | null
    fiscal_method: string
    status: string
  }
  assetGroups: Array<{ value: number; label: string }>
  accounts: Array<{ value: number; label: string }>
}>()

const methodOptions = [
  { value: 'straight_line', label: 'Straight-line' },
  { value: 'declining_balance', label: 'Declining-balance' },
]

const form = useForm({
  asset_group_id: props.asset.asset_group_id,
  asset_no: props.asset.asset_no,
  name: props.asset.name,
  vendor_partner_id: props.asset.vendor_partner_id,
  acquisition_date: props.asset.acquisition_date,
  acquisition_cost: Number(props.asset.acquisition_cost) || null,
  asset_gl_account_id: props.asset.asset_gl_account_id,
  accumulated_depreciation_gl_account_id: props.asset.accumulated_depreciation_gl_account_id,
  depreciation_expense_gl_account_id: props.asset.depreciation_expense_gl_account_id,
  commercial_useful_life_months: Number(props.asset.commercial_useful_life_months) || null,
  commercial_method: props.asset.commercial_method,
  commercial_declining_rate: props.asset.commercial_declining_rate ?? '',
  fiscal_method: props.asset.fiscal_method,
})

const submit = () => form.transform((data) => ({
  ...data,
  acquisition_cost: Number(data.acquisition_cost) || 0,
  commercial_useful_life_months: Number(data.commercial_useful_life_months) || 0,
  commercial_declining_rate: data.commercial_method === 'declining_balance' && data.commercial_declining_rate !== '' ? Number(data.commercial_declining_rate) : null,
})).put(route('accounting.fixed-assets.update', props.asset.id))
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Edit ${asset.asset_no}`" :description="asset.name" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.asset_group_id" name="asset_group_id" label="Asset Group" :options="assetGroups" :error="form.errors.asset_group_id" required />
          <FormInput v-model="form.asset_no" name="asset_no" label="Asset Number / Tag" :error="form.errors.asset_no" required />
          <FormInput v-model="form.name" name="name" label="Asset Name" :error="form.errors.name" required />
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
              label="Declining-Balance Rate (annual)"
              :error="form.errors.commercial_declining_rate"
              required
            />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-2 text-sm font-semibold text-ink-900">Fiscal Depreciation (Tax)</div>
          <FormSearchableSelect v-model="form.fiscal_method" name="fiscal_method" label="Fiscal Method" :options="methodOptions" :error="form.errors.fiscal_method" required class="max-w-xs" />
        </div>

        <p v-if="asset.status === 'disposed'" class="text-sm text-signal-danger">This asset has been disposed and cannot be modified.</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.fixed-assets.index', { company_id: asset.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing || asset.status === 'disposed'">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
