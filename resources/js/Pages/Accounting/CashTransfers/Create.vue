<!-- ponytail: Accounting §3F inter-account transfer — same-currency only in v1, see CashTransferService docblock. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  bankAccounts: Array<{ value: number; label: string }>
  selectedFromBankAccountId: number | null
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  from_bank_account_id: props.selectedFromBankAccountId,
  to_bank_account_id: null as number | null,
  transfer_date: new Date().toISOString().slice(0, 10),
  amount: null as number | null,
  description: '',
})

const backHref = computed(() =>
  form.from_bank_account_id ? route('accounting.bank-accounts.show', form.from_bank_account_id) : route('accounting.bank-accounts.index', { company_id: form.company_id }),
)

const submit = () => form.transform((data) => ({ ...data, amount: Number(data.amount) || 0 })).post(route('accounting.cash-transfers.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Transfer Between Accounts" description="Inter-account fund transfer between same-currency bank or cash accounts." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSearchableSelect v-model="form.from_bank_account_id" name="from_bank_account_id" label="Source Account (From)" :options="bankAccounts" :error="form.errors.from_bank_account_id" required />
        <FormSearchableSelect v-model="form.to_bank_account_id" name="to_bank_account_id" label="Destination Account (To)" :options="bankAccounts" :error="form.errors.to_bank_account_id" required />
        <FormInput v-model="form.transfer_date" name="transfer_date" type="date" label="Transfer Date" :error="form.errors.transfer_date" required />
        <FormCurrencyInput v-model="form.amount" name="amount" label="Transfer Amount" :error="form.errors.amount" required />
        <FormInput v-model="form.description" name="description" label="Description / Reference" placeholder="e.g. Replenish petty cash" :error="form.errors.description" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="backHref">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Post Transfer</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
