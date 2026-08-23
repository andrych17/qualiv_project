<!-- ponytail: Accounting §3B Chart of Accounts — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
    <PageHeader title="Edit account" :description="`${account.account_code} — ${account.account_name}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.account_code" name="account_code" label="Account code" :error="form.errors.account_code" required />
        <FormInput v-model="form.account_name" name="account_name" label="Account name" :error="form.errors.account_name" required />
        <FormSelect
          v-model="form.account_type"
          name="account_type"
          label="Account type"
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
          label="Normal balance"
          :options="[{ label: 'Debit', value: 'debit' }, { label: 'Credit', value: 'credit' }]"
          :error="form.errors.normal_balance"
          required
        />
        <FormSearchableSelect v-model="form.parent_account_id" name="parent_account_id" label="Parent account" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_account_id" />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_control_account" type="checkbox" class="rounded border-border" />
          Control account (AR/AP/Inventory — rejects direct manual journal lines)
        </label>
        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_active" type="checkbox" class="rounded border-border" />
          Active
        </label>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.accounts.index', { company_id: account.company_id })"
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
