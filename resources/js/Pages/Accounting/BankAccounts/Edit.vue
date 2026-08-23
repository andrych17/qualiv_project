<!-- ponytail: Accounting §3F bank account — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  bankAccount: {
    id: number
    company_id: number
    name: string
    bank_name: string | null
    account_number: string | null
    account_holder_name: string | null
    currency_code: string
    gl_account_id: number
    is_active: boolean
  }
  currencies: Array<{ code: string; name: string }>
  accounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  name: props.bankAccount.name,
  bank_name: props.bankAccount.bank_name ?? '',
  account_number: props.bankAccount.account_number ?? '',
  account_holder_name: props.bankAccount.account_holder_name ?? '',
  currency_code: props.bankAccount.currency_code,
  gl_account_id: props.bankAccount.gl_account_id,
  is_active: props.bankAccount.is_active,
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const submit = () => form.put(route('accounting.bank-accounts.update', props.bankAccount.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit bank account" :description="bankAccount.name" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Display name" :error="form.errors.name" required />
        <FormInput v-model="form.bank_name" name="bank_name" label="Bank name" :error="form.errors.bank_name" />
        <FormInput v-model="form.account_number" name="account_number" label="Account number" :error="form.errors.account_number" />
        <FormInput v-model="form.account_holder_name" name="account_holder_name" label="Account holder name" :error="form.errors.account_holder_name" />
        <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" label="GL cash/bank account" :options="accounts" :error="form.errors.gl_account_id" required />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_active" type="checkbox" class="rounded border-border" />
          Active
        </label>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.bank-accounts.index', { company_id: bankAccount.company_id })"
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
