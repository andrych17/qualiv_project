<!-- ponytail: Edit Product (§3B) — tabbed detail view. Stock Card (§3H) is its own report
     page, not a tab here — a product's ledger history can be long, so it's not worth eagerly
     loading on every edit view; "View stock card" deep-links into it instead. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import BarcodeListInput, { type BarcodeRow } from '@/Components/inventory/BarcodeListInput.vue'
import UomConversionListInput, { type UomConversionRow } from '@/Components/inventory/UomConversionListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  product: {
    id: number
    sku: string
    name: string
    description: string | null
    category_id: number | null
    base_uom_id: number
    costing_method: string
    reorder_point: number
    reorder_quantity: number
    tracking_mode: string
    is_active: boolean
    barcodes: BarcodeRow[]
    uom_conversions: UomConversionRow[]
  }
  categories: Array<{ id: number; label: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  sku: props.product.sku,
  name: props.product.name,
  description: props.product.description ?? '',
  category_id: props.product.category_id,
  base_uom_id: props.product.base_uom_id,
  costing_method: props.product.costing_method,
  reorder_point: props.product.reorder_point,
  reorder_quantity: props.product.reorder_quantity,
  tracking_mode: props.product.tracking_mode,
  is_active: props.product.is_active,
  barcodes: props.product.barcodes.map((b) => ({ ...b })),
  uom_conversions: props.product.uom_conversions.map((c) => ({ ...c })),
  custom_fields: customBag,
})

const baseUomCode = computed(() => props.uoms.find((u) => u.id === form.base_uom_id)?.code)

const tab = ref<'overview' | 'barcodes' | 'custom_fields'>('overview')
const tabs = [
  { key: 'overview', label: 'Overview' },
  { key: 'barcodes', label: 'Barcodes', count: form.barcodes.length },
  { key: 'custom_fields', label: 'Custom Fields' },
]

const submit = () => form.put(route('inventory.products.update', props.product.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit product" :description="product.sku">
      <template #actions>
        <Link :href="route('inventory.stockCard.index', { product_id: product.id })" class="text-sm font-medium text-accent hover:underline">
          View stock card
        </Link>
      </template>
    </PageHeader>

    <InventorySubNav active="products" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <Tabs v-model="tab" :tabs="tabs" />

        <div v-show="tab === 'overview'" class="space-y-4 pt-2">
          <FormInput v-model="form.sku" name="sku" label="SKU" :error="form.errors.sku" required />
          <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
          <FormTextarea v-model="form.description" name="description" label="Description" :error="form.errors.description" />
          <FormSelect
            v-model="form.category_id"
            name="category_id"
            label="Category"
            placeholder="Uncategorized"
            :options="categories.map((c) => ({ label: c.label, value: c.id }))"
            :error="form.errors.category_id"
          />
          <FormSelect
            v-model="form.base_uom_id"
            name="base_uom_id"
            label="Base UoM"
            placeholder="Select a UoM…"
            :options="uoms.map((u) => ({ label: `${u.code} — ${u.name}`, value: u.id }))"
            :error="form.errors.base_uom_id"
            required
          />
          <FormSelect
            v-model="form.costing_method"
            name="costing_method"
            label="Costing method"
            :options="[
              { label: 'FIFO', value: 'fifo' },
              { label: 'Weighted Average', value: 'average' },
            ]"
            :error="form.errors.costing_method"
          />
          <div class="grid grid-cols-2 gap-4">
            <FormInput v-model.number="form.reorder_point" name="reorder_point" type="number" label="Reorder point" :error="form.errors.reorder_point" />
            <FormInput v-model.number="form.reorder_quantity" name="reorder_quantity" type="number" label="Reorder quantity" :error="form.errors.reorder_quantity" />
          </div>
          <FormSelect
            v-model="form.tracking_mode"
            name="tracking_mode"
            label="Tracking mode"
            :options="[
              { label: 'None', value: 'none' },
              { label: 'Batch / Lot', value: 'batch' },
              { label: 'Serial', value: 'serial' },
            ]"
            :error="form.errors.tracking_mode"
          />
          <p v-if="form.tracking_mode === 'batch'" class="text-xs text-ink-600">
            Every Receipt/Issue/Transfer/Adjustment line for this product will require a lot number (§3L).
          </p>
          <p v-else-if="form.tracking_mode === 'serial'" class="text-xs text-ink-600">
            Every Receipt/Issue/Transfer line for this product will require naming each unit's
            serial number (§3M). Adjustments aren't supported for serial-tracked products yet —
            correct via a Receipt or Issue instead.
          </p>

          <UomConversionListInput v-model="form.uom_conversions" :uoms="uoms" :base-uom-code="baseUomCode" />

          <FormSwitch v-model="form.is_active" label="Active" description="Inactive products are hidden from new receipts/issues but keep their ledger history." />
        </div>

        <div v-show="tab === 'barcodes'" class="pt-2">
          <BarcodeListInput v-model="form.barcodes" />
        </div>

        <div v-show="tab === 'custom_fields'" class="pt-2">
          <CustomFieldInputs
            v-model="form.custom_fields"
            :fields="customFields"
            :errors="form.errors"
          />
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.products.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update product</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
