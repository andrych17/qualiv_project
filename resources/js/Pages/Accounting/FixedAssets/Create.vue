<!-- ponytail: Accounting §3G new fixed asset. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
  acquisition_cost: '',
  asset_gl_account_id: null as number | null,
  accumulated_depreciation_gl_account_id: null as number | null,
  depreciation_expense_gl_account_id: null as number | null,
  commercial_useful_life_months: '',
  commercial_method: 'straight_line',
  commercial_declining_rate: '',
  fiscal_method: 'straight_line',
})

const submit = () => form.transform((data) => ({
  ...data,
  acquisition_cost: Number(data.acquisition_cost),
  commercial_useful_life_months: Number(data.commercial_useful_life_months),
  commercial_declining_rate: data.commercial_method === 'declining_balance' && data.commercial_declining_rate !== '' ? Number(data.commercial_declining_rate) : null,
})).post(route('accounting.fixed-assets.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New fixed asset" />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
          <FormSearchableSelect v-model="form.asset_group_id" name="asset_group_id" label="Asset group" :options="assetGroups" :error="form.errors.asset_group_id" required />
          <FormInput v-model="form.asset_no" name="asset_no" label="Asset number" :error="form.errors.asset_no" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormAsyncSearchableSelect v-model="form.vendor_partner_id" name="vendor_partner_id" label="Vendor (optional)" api-entity="crm_partner" placeholder="Search vendor..." :error="form.errors.vendor_partner_id" />
          <FormInput v-model="form.acquisition_date" name="acquisition_date" type="date" label="Acquisition date" :error="form.errors.acquisition_date" required />
          <FormInput v-model="form.acquisition_cost" name="acquisition_cost" type="number" step="0.01" label="Acquisition cost" :error="form.errors.acquisition_cost" required />
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-2 text-sm font-semibold text-ink-900">GL accounts</div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <FormSearchableSelect v-model="form.asset_gl_account_id" name="asset_gl_account_id" label="Fixed asset account" :options="accounts" :error="form.errors.asset_gl_account_id" required />
            <FormSearchableSelect v-model="form.accumulated_depreciation_gl_account_id" name="accumulated_depreciation_gl_account_id" label="Accumulated depreciation account" :options="accounts" :error="form.errors.accumulated_depreciation_gl_account_id" required />
            <FormSearchableSelect v-model="form.depreciation_expense_gl_account_id" name="depreciation_expense_gl_account_id" label="Depreciation expense account" :options="accounts" :error="form.errors.depreciation_expense_gl_account_id" required />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-2 text-sm font-semibold text-ink-900">Commercial depreciation</div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormInput v-model="form.commercial_useful_life_months" name="commercial_useful_life_months" type="number" label="Useful life (months)" :error="form.errors.commercial_useful_life_months" required />
            <FormSearchableSelect v-model="form.commercial_method" name="commercial_method" label="Method" :options="methodOptions" :error="form.errors.commercial_method" required />
            <FormInput
              v-if="form.commercial_method === 'declining_balance'"
              v-model="form.commercial_declining_rate"
              name="commercial_declining_rate"
              type="number"
              step="0.0001"
              label="Declining-balance rate (annual, e.g. 0.50)"
              :error="form.errors.commercial_declining_rate"
              required
            />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <div class="mb-2 text-sm font-semibold text-ink-900">Fiscal depreciation</div>
          <p class="mb-2 text-xs text-ink-600">Rate and useful life come from the asset group — only the method is elected per asset.</p>
          <FormSearchableSelect v-model="form.fiscal_method" name="fiscal_method" label="Method" :options="methodOptions" :error="form.errors.fiscal_method" required class="max-w-xs" />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.fixed-assets.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create asset</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
