<!-- ponytail: Accounting §3L exchange rates — edit form. Currency/company are fixed at creation. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
    <PageHeader title="Edit exchange rate" :description="exchangeRate.currency_code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.rate_to_base" name="rate_to_base" type="number" step="0.000001" label="Rate to base currency" :error="form.errors.rate_to_base" required />
        <FormInput v-model="form.effective_date" name="effective_date" type="date" label="Effective date" :error="form.errors.effective_date" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.exchange-rates.index', { company_id: exchangeRate.company_id })"
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
