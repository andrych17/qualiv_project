<!-- ponytail: Accounting §3B Chart of Accounts — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  account_code: '',
  account_name: '',
  account_type: 'asset',
  normal_balance: 'debit',
  parent_account_id: null as number | null,
  is_control_account: false,
})

const companyOptions = props.companies.map((c) => ({ value: c.id, label: c.legal_name }))

const submit = () => form.post(route('accounting.accounts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Account" description="Chart of Accounts entry — 1xxxx Aset, 2xxxx Liabilitas, 3xxxx Ekuitas, 4xxxx Pendapatan, 5xxxx HPP, 6xxxx Beban." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companyOptions" :error="form.errors.company_id" required />
        <FormInput v-model="form.account_code" name="account_code" label="Account Code" :error="form.errors.account_code" required />
        <FormInput v-model="form.account_name" name="account_name" label="Account Name" :error="form.errors.account_name" required />
        <FormSelect
          v-model="form.account_type"
          name="account_type"
          label="Account Type"
          :options="[
            { label: 'Asset (Aset)', value: 'asset' },
            { label: 'Liability (Liabilitas)', value: 'liability' },
            { label: 'Equity (Ekuitas)', value: 'equity' },
            { label: 'Revenue (Pendapatan)', value: 'revenue' },
            { label: 'COGS (HPP)', value: 'cogs' },
            { label: 'Expense (Beban)', value: 'expense' },
          ]"
          :error="form.errors.account_type"
          required
        />
        <FormSelect
          v-model="form.normal_balance"
          name="normal_balance"
          label="Normal Balance"
          :options="[{ label: 'Debit', value: 'debit' }, { label: 'Credit', value: 'credit' }]"
          :error="form.errors.normal_balance"
          required
        />
        <FormSearchableSelect v-model="form.parent_account_id" name="parent_account_id" label="Parent Account" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_account_id" />

        <FormSwitch
          v-model="form.is_control_account"
          name="is_control_account"
          label="Control Account (AR/AP/Inventory — rejects direct manual journal entries)"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.accounts.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Account</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
