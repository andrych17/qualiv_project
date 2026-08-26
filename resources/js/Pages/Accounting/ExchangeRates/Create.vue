<!-- ponytail: Accounting §3L exchange rates — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  currencies: Array<{ code: string; name: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  currency_code: null as string | null,
  rate_to_base: '',
  effective_date: new Date().toISOString().slice(0, 10),
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const submit = () => form.transform((data) => ({ ...data, rate_to_base: Number(data.rate_to_base) })).post(route('accounting.exchange-rates.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Exchange Rate" description="Record foreign exchange spot or monthly tax conversion rates to company functional currency." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Foreign Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
        <FormInput v-model="form.rate_to_base" name="rate_to_base" type="number" step="0.000001" label="Exchange Rate (to 1 Base Currency)" placeholder="e.g. 15850.00" :error="form.errors.rate_to_base" required />
        <FormInput v-model="form.effective_date" name="effective_date" type="date" label="Effective Date" :error="form.errors.effective_date" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.exchange-rates.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Add Rate</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
