<!-- Purchase Catalog Item Create (§3I) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface CategoryItem {
  id: number
  name: string
  kind: string
  capex_opex: string
}

interface VendorItem {
  id: number
  name: string
}

const props = defineProps<{
  categories: CategoryItem[]
  vendors: VendorItem[]
}>()

const form = useForm({
  item_code: '',
  description: '',
  category_id: null as number | null,
  unit: 'unit',
  preferred_supplier_id: null as number | null,
  negotiated_price: null as number | null,
  price_valid_from: null as string | null,
  price_valid_to: null as string | null,
  is_active: true,
})

const submit = () => form.post(route('purchase.catalog.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Catalog Item" description="Define standard procurement item specifications and supplier contracted rates (§3I).">
      <template #actions>
        <SecondaryButton :href="route('purchase.catalog.index')">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6 max-w-3xl" @submit.prevent="submit">
      <Panel title="Item Specifications">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormInput
            v-model="form.item_code"
            name="item_code"
            label="Item Code / SKU *"
            placeholder="e.g. IT-LAPTOP-001"
            :error="form.errors.item_code"
            required
          />

          <FormInput
            v-model="form.unit"
            name="unit"
            label="Unit of Measure *"
            placeholder="e.g. unit, pcs, box, kg, meter"
            :error="form.errors.unit"
            required
          />
        </div>

        <div class="mt-4">
          <FormInput
            v-model="form.description"
            name="description"
            label="Item Description *"
            placeholder="e.g. ThinkPad T14 Gen 4 Core i7 32GB RAM"
            :error="form.errors.description"
            required
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormSelect
            v-model="form.category_id"
            name="category_id"
            label="Spend Category"
            placeholder="Select category"
            :options="categories.map((c) => ({ label: `${c.name} (${c.kind.toUpperCase()})`, value: c.id }))"
            :error="form.errors.category_id"
          />

          <FormSelect
            v-model="form.preferred_supplier_id"
            name="preferred_supplier_id"
            label="Preferred Supplier / Vendor"
            placeholder="Select vendor"
            :options="vendors.map((v) => ({ label: v.name, value: v.id }))"
            :error="form.errors.preferred_supplier_id"
          />
        </div>
      </Panel>

      <Panel title="Contract / Negotiated Pricing">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <FormInput
            v-model.number="form.negotiated_price"
            name="negotiated_price"
            type="number"
            step="0.01"
            min="0"
            label="Negotiated Price (IDR)"
            placeholder="0.00"
            :error="form.errors.negotiated_price"
          />

          <FormInput
            v-model="form.price_valid_from"
            name="price_valid_from"
            type="date"
            label="Price Valid From"
            :error="form.errors.price_valid_from"
          />

          <FormInput
            v-model="form.price_valid_to"
            name="price_valid_to"
            type="date"
            label="Price Valid To"
            :error="form.errors.price_valid_to"
          />
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.catalog.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">
          Save Catalog Item
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
