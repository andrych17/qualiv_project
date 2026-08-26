<!-- ponytail: Add Product (§3B) -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import BarcodeListInput, { type BarcodeRow } from '@/Components/inventory/BarcodeListInput.vue'
import UomConversionListInput, { type UomConversionRow } from '@/Components/inventory/UomConversionListInput.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  categories: Array<{ id: number; label: string }>
  uoms: Array<{ id: number; code: string; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  sku: '',
  name: '',
  description: '',
  category_id: null as number | null,
  base_uom_id: null as number | null,
  costing_method: '' as string,
  reorder_point: 0,
  reorder_quantity: 0,
  tracking_mode: 'none',
  barcodes: [] as BarcodeRow[],
  uom_conversions: [] as UomConversionRow[],
  custom_fields: customBag,
})

const baseUomCode = computed(() => props.uoms.find((u) => u.id === form.base_uom_id)?.code)

const submit = () => form.post(route('inventory.products.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add product" description="Register a SKU — barcodes and additional UoMs can be added below." />

    <InventorySubNav active="products" class="mt-6" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
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
          placeholder="Use tenant default"
          :options="[
            { label: 'FIFO', value: 'fifo' },
            { label: 'Weighted Average', value: 'average' },
          ]"
          :error="form.errors.costing_method"
        />
        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.reorder_point" name="reorder_point" label="Reorder point" :error="form.errors.reorder_point" />
          <FormNumberInput v-model="form.reorder_quantity" name="reorder_quantity" label="Reorder quantity" :error="form.errors.reorder_quantity" />
        </div>

        <BarcodeListInput v-model="form.barcodes" />
        <UomConversionListInput v-model="form.uom_conversions" :uoms="uoms" :base-uom-code="baseUomCode" />

        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.products.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save product</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
