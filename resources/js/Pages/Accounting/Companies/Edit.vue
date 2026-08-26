<!-- ponytail: Accounting §3K minimal Companies master — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  company: {
    id: number
    legal_name: string
    npwp: string | null
    address: string | null
    base_currency: string
    fiscal_year_start_month: number
    ar_control_account_id: number | null
    ap_control_account_id: number | null
    inventory_control_account_id: number | null
    payroll_net_pay_payable_account_id: number | null
    is_active: boolean
  }
  controlAccounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  legal_name: props.company.legal_name,
  npwp: props.company.npwp ?? '',
  address: props.company.address ?? '',
  base_currency: props.company.base_currency,
  fiscal_year_start_month: props.company.fiscal_year_start_month as number | null,
  ar_control_account_id: props.company.ar_control_account_id,
  ap_control_account_id: props.company.ap_control_account_id,
  inventory_control_account_id: props.company.inventory_control_account_id,
  payroll_net_pay_payable_account_id: props.company.payroll_net_pay_payable_account_id,
  is_active: props.company.is_active,
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_year_start_month: Number(data.fiscal_year_start_month),
})).put(route('accounting.companies.update', props.company.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Company" :description="company.legal_name" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.legal_name" name="legal_name" label="Legal Name" :error="form.errors.legal_name" required />
        <FormInput v-model="form.npwp" name="npwp" label="NPWP (Tax ID)" :error="form.errors.npwp" />
        <FormInput v-model="form.address" name="address" label="Registered Address" :error="form.errors.address" />
        <FormInput v-model="form.base_currency" name="base_currency" label="Base Functional Currency" :error="form.errors.base_currency" required />
        <FormNumberInput v-model="form.fiscal_year_start_month" name="fiscal_year_start_month" label="Fiscal Year Start Month (1-12)" :min="1" :max="12" :error="form.errors.fiscal_year_start_month" required />
        <FormSearchableSelect
          v-model="form.ar_control_account_id"
          name="ar_control_account_id"
          label="AR Control Account"
          placeholder="None — AR invoices cannot post until set"
          :options="controlAccounts"
          :error="form.errors.ar_control_account_id"
        />
        <FormSearchableSelect
          v-model="form.ap_control_account_id"
          name="ap_control_account_id"
          label="AP Control Account"
          placeholder="None — AP bills cannot post until set"
          :options="controlAccounts"
          :error="form.errors.ap_control_account_id"
        />
        <FormSearchableSelect
          v-model="form.inventory_control_account_id"
          name="inventory_control_account_id"
          label="Inventory Control Account"
          placeholder="None — used by control reconciliation reports"
          :options="controlAccounts"
          :error="form.errors.inventory_control_account_id"
        />
        <FormSearchableSelect
          v-model="form.payroll_net_pay_payable_account_id"
          name="payroll_net_pay_payable_account_id"
          label="Payroll Net Pay Payable Account"
          placeholder="None — payroll runs will fail loudly until set"
          :options="controlAccounts"
          :error="form.errors.payroll_net_pay_payable_account_id"
        />

        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.companies.index')">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
