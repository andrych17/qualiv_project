<!-- ponytail: Add QC Inspection Plan (MES_SPECS.md §3L) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import QcCharacteristicListInput, { type QcCharacteristicRow } from '@/Components/mes/QcCharacteristicListInput.vue'

const form = useForm({
  product_id: null as number | null,
  name: '',
  characteristics: [] as QcCharacteristicRow[],
})

const submit = () => form.post(route('mes.qcPlans.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add QC Inspection Plan" description="Optionally scope to one product — leave blank for a plan any order can use." />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Plan Name" placeholder="e.g. Bracket Assembly Final QC" :error="form.errors.name" required />

        <FormAsyncSearchableSelect
          v-model="form.product_id"
          name="product_id"
          label="Product (optional)"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :error="form.errors.product_id"
        />

        <QcCharacteristicListInput v-model="form.characteristics" />
        <p v-if="form.errors.characteristics" class="text-sm text-signal-danger">{{ form.errors.characteristics }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.qcPlans.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Plan</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
