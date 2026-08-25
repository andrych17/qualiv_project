<!-- ponytail: New Goods Receipt (§3D) — always starts as a draft; posting is a separate step. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import GoodsReceiptLineListInput, { type ReceiptLineRow } from '@/Components/inventory/GoodsReceiptLineListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  productTracking: Record<number, string>
}>()

const form = useForm({
  warehouse_id: null as number | null,
  receipt_date: new Date().toISOString().slice(0, 10),
  reference_number: '',
  lines: [] as ReceiptLineRow[],
})

const submit = () => form.post(route('inventory.goodsReceipts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Goods Receipt" description="Saved as a draft — post it once the lines are correct." />

    <InventorySubNav active="goodsReceipts" class="mt-6" />

    <Panel class="mt-6 max-w-4xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormSelect
            v-model="form.warehouse_id"
            name="warehouse_id"
            label="Warehouse"
            placeholder="Select a warehouse…"
            :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
            :error="form.errors.warehouse_id"
            required
          />
          <FormInput v-model="form.receipt_date" name="receipt_date" type="date" label="Receipt date" :error="form.errors.receipt_date" required />
        </div>
        <FormInput v-model="form.reference_number" name="reference_number" label="Reference number" placeholder="e.g. supplier invoice #" :error="form.errors.reference_number" />

        <GoodsReceiptLineListInput
          v-model="form.lines"
          :uoms="uoms"
          :locations="locations"
          :warehouse-id="form.warehouse_id"
          :product-tracking="productTracking"
        />
        <p v-if="form.errors.lines" class="text-sm text-signal-danger">{{ form.errors.lines }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.goodsReceipts.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save draft</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
