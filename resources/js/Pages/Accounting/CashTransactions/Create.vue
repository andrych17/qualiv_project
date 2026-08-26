<!-- ponytail: Accounting §3F cash in/out — guided 2-field form; submit both creates and posts in one step. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  bankAccounts: Array<{ value: number; label: string }>
  selectedBankAccountId: number | null
  accounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  bank_account_id: props.selectedBankAccountId,
  direction: 'in',
  transaction_date: new Date().toISOString().slice(0, 10),
  amount: null as number | null,
  offset_account_id: null as number | null,
  description: '',
})

const backHref = computed(() =>
  form.bank_account_id ? route('accounting.bank-accounts.show', form.bank_account_id) : route('accounting.bank-accounts.index', { company_id: form.company_id }),
)

const submit = () => form.transform((data) => ({ ...data, amount: Number(data.amount) || 0 })).post(route('accounting.cash-transactions.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Direct Cash Transaction" description="Direct cash receipt or disbursement not tied to an AR/AP invoice (e.g. petty cash, bank fees, interest income)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSearchableSelect v-model="form.bank_account_id" name="bank_account_id" label="Bank / Cash Account" :options="bankAccounts" :error="form.errors.bank_account_id" required />
        <FormSelect
          v-model="form.direction"
          name="direction"
          label="Transaction Direction"
          :options="[{ label: 'Cash In (Receipt)', value: 'in' }, { label: 'Cash Out (Disbursement)', value: 'out' }]"
          :error="form.errors.direction"
          required
        />
        <FormInput v-model="form.transaction_date" name="transaction_date" type="date" label="Transaction Date" :error="form.errors.transaction_date" required />
        <FormCurrencyInput v-model="form.amount" name="amount" label="Amount" :error="form.errors.amount" required />
        <FormSearchableSelect v-model="form.offset_account_id" name="offset_account_id" label="Offset Account (Revenue / Expense)" :options="accounts" :error="form.errors.offset_account_id" required />
        <FormInput v-model="form.description" name="description" label="Description / Memo" placeholder="e.g. Monthly bank administration fee" :error="form.errors.description" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="backHref">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Post Transaction</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
