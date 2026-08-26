<!-- ponytail: Accounting §3L exchange rates — edit form. Currency/company are fixed at creation. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  exchangeRate: {
    id: number
    company_id: number
    currency_code: string
    rate_to_base: number
    effective_date: string
  }
}>()

const form = useForm({
  rate_to_base: props.exchangeRate.rate_to_base,
  effective_date: props.exchangeRate.effective_date,
})

const submit = () => form.transform((data) => ({ ...data, rate_to_base: Number(data.rate_to_base) })).put(route('accounting.exchange-rates.update', props.exchangeRate.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Exchange Rate" :description="`Currency: ${exchangeRate.currency_code}`" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.rate_to_base" name="rate_to_base" type="number" step="0.000001" label="Exchange Rate (to 1 Base Currency)" :error="form.errors.rate_to_base" required />
        <FormInput v-model="form.effective_date" name="effective_date" type="date" label="Effective Date" :error="form.errors.effective_date" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.exchange-rates.index', { company_id: exchangeRate.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
