<!-- ponytail: Edit Demand Forecast (PP_SPECS.md §3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  forecast: {
    id: number
    product_id: number
    product_label: string | null
    period_start: string
    qty: number
    source: 'manual' | 'import'
    note: string | null
  }
}>()

const form = useForm({
  product_id: props.forecast.product_id,
  period_start: props.forecast.period_start,
  qty: props.forecast.qty,
  source: props.forecast.source,
  note: props.forecast.note ?? '',
})

const submit = () => form.put(route('pp.demandForecasts.update', props.forecast.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit forecast" :description="forecast.product_label ?? undefined" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput :model-value="forecast.product_label ?? ''" name="product_label" label="Product" disabled />

        <FormInput v-model="form.period_start" name="period_start" label="Period start" type="date" :error="form.errors.period_start" required />
        <FormNumberInput v-model="form.qty" name="qty" label="Forecast qty" :decimals="4" :error="form.errors.qty" required />

        <FormSelect
          v-model="form.source"
          name="source"
          label="Source"
          :options="[
            { label: 'Manual', value: 'manual' },
            { label: 'Import', value: 'import' },
          ]"
          :error="form.errors.source"
        />

        <FormInput v-model="form.note" name="note" label="Note" :error="form.errors.note" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('pp.demandForecasts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save forecast</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
