<!-- ponytail: Add Demand Forecast (PP_SPECS.md §3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const form = useForm({
  product_id: null as number | null,
  period_start: new Date().toISOString().slice(0, 10),
  qty: 0,
  source: 'manual' as 'manual' | 'import',
  note: '',
})

const submit = () => form.post(route('pp.demandForecasts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add forecast" description="Projected demand for a product in a future period." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormAsyncSearchableSelect
          v-model="form.product_id"
          name="product_id"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :error="form.errors.product_id"
          required
        />

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
