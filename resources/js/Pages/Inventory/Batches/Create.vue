<!-- ponytail: Add Batch (§3L) — pre-registers a lot before any receipt against it exists. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const form = useForm({
  product_id: null as number | null,
  batch_number: '',
  expiry_date: '',
  manufacture_date: '',
  supplier_reference: '',
})

const submit = () => form.post(route('inventory.batches.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add batch" description="Most lots are created automatically from a Goods Receipt — use this to pre-register one before stock exists." />

    <InventorySubNav active="batches" class="mt-6" />

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
        <FormInput v-model="form.batch_number" name="batch_number" label="Lot number" :error="form.errors.batch_number" required />
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.manufacture_date" name="manufacture_date" type="date" label="Manufacture date" :error="form.errors.manufacture_date" />
          <FormInput v-model="form.expiry_date" name="expiry_date" type="date" label="Expiry date" :error="form.errors.expiry_date" />
        </div>
        <FormInput v-model="form.supplier_reference" name="supplier_reference" label="Supplier reference" :error="form.errors.supplier_reference" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.batches.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save batch</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
