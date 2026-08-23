<!-- ponytail: Accounting §3F inter-account transfer — same-currency only in v1, see CashTransferService docblock. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
  amount: '',
  description: '',
})

const backHref = computed(() =>
  form.from_bank_account_id ? route('accounting.bank-accounts.show', form.from_bank_account_id) : route('accounting.bank-accounts.index', { company_id: form.company_id }),
)

const submit = () => form.transform((data) => ({ ...data, amount: Number(data.amount) })).post(route('accounting.cash-transfers.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Transfer between accounts" description="Same-currency accounts only in v1 — a cross-currency transfer's destination amount depends on the bank's own settlement rate, not one this module can predict." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSearchableSelect v-model="form.from_bank_account_id" name="from_bank_account_id" label="From account" :options="bankAccounts" :error="form.errors.from_bank_account_id" required />
        <FormSearchableSelect v-model="form.to_bank_account_id" name="to_bank_account_id" label="To account" :options="bankAccounts" :error="form.errors.to_bank_account_id" required />
        <FormInput v-model="form.transfer_date" name="transfer_date" type="date" label="Date" :error="form.errors.transfer_date" required />
        <FormInput v-model="form.amount" name="amount" type="number" step="0.01" label="Amount" :error="form.errors.amount" required />
        <FormInput v-model="form.description" name="description" label="Description" :error="form.errors.description" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="backHref"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Post transfer</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
