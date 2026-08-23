<!-- ponytail: Accounting §3F bank account — create form. -->
<script setup lang="ts">
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
  currencies: Array<{ code: string; name: string }>
  accounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  name: '',
  bank_name: '',
  account_number: '',
  account_holder_name: '',
  currency_code: null as string | null,
  gl_account_id: null as number | null,
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const submit = () => form.post(route('accounting.bank-accounts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New bank account" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.name" name="name" label="Display name" placeholder="e.g. BCA Operational" :error="form.errors.name" required />
        <FormInput v-model="form.bank_name" name="bank_name" label="Bank name" :error="form.errors.bank_name" />
        <FormInput v-model="form.account_number" name="account_number" label="Account number" :error="form.errors.account_number" />
        <FormInput v-model="form.account_holder_name" name="account_holder_name" label="Account holder name" :error="form.errors.account_holder_name" />
        <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" label="GL cash/bank account" :options="accounts" :error="form.errors.gl_account_id" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.bank-accounts.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create bank account</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
