<!-- ponytail: Accounting §3F bank account — edit form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

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
    <PageHeader title="Edit Bank Account" :description="bankAccount.name" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Display Name" :error="form.errors.name" required />
        <FormInput v-model="form.bank_name" name="bank_name" label="Bank Name" :error="form.errors.bank_name" />
        <FormInput v-model="form.account_number" name="account_number" label="Account Number" :error="form.errors.account_number" />
        <FormInput v-model="form.account_holder_name" name="account_holder_name" label="Account Holder Name" :error="form.errors.account_holder_name" />
        <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" label="GL Cash/Bank Account" :options="accounts" :error="form.errors.gl_account_id" required />

        <FormSwitch
          v-model="form.is_active"
          name="is_active"
          label="Active Status"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.bank-accounts.index', { company_id: bankAccount.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
