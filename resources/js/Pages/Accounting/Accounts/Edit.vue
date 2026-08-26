<!-- ponytail: Accounting §3B Chart of Accounts — edit form. -->
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
  account: {
    id: number
    company_id: number
    account_code: string
    account_name: string
    account_type: string
    normal_balance: string
    parent_account_id: number | null
    is_control_account: boolean
    is_active: boolean
  }
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  account_code: props.account.account_code,
  account_name: props.account.account_name,
  account_type: props.account.account_type,
  normal_balance: props.account.normal_balance,
  parent_account_id: props.account.parent_account_id,
  is_control_account: props.account.is_control_account,
  is_active: props.account.is_active,
})

const submit = () => form.put(route('accounting.accounts.update', props.account.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Account" :description="`${account.account_code} — ${account.account_name}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
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
          label="Control Account (AR/AP/Inventory — rejects direct manual journal lines)"
        />
        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.accounts.index', { company_id: account.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
