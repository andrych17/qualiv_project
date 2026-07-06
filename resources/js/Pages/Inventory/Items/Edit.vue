<!-- ponytail: Simple Inventory editing form using Inertia useForm helper -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'

interface InventoryItem {
  id: number
  code: string
  name: string
  description: string | null
  inventory_category_id: number
  stock: number
  minimum_stock: number
  unit: string
  status: string
}

const props = defineProps<{
  item: InventoryItem
  categories: Array<{ label: string; value: string | number }>
}>()

const form = useForm({
  code: props.item.code,
  name: props.item.name,
  description: props.item.description ?? '',
  inventory_category_id: props.item.inventory_category_id,
  stock: props.item.stock,
  minimum_stock: props.item.minimum_stock,
  unit: props.item.unit,
  status: props.item.status,
})

const submit = () => {
  form.put(route('inventory.items.update', props.item.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Edit Inventory Item"
      :description="`Update details for ${item.name} (${item.code}).`"
    />

    <div class="mt-6 max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <form @submit.prevent="submit" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormInput
            v-model="form.code"
            name="code"
            label="Item Code"
            placeholder="e.g. RAW-001"
            :error="form.errors.code"
            required
          />
          
          <FormInput
            v-model="form.name"
            name="name"
            label="Item Name"
            placeholder="e.g. Steel Plate"
            :error="form.errors.name"
            required
          />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormSelect
            v-model="form.inventory_category_id"
            name="inventory_category_id"
            label="Category"
            placeholder="Select category"
            :options="categories"
            :error="form.errors.inventory_category_id"
            required
          />

          <FormInput
            v-model="form.unit"
            name="unit"
            label="Unit of Measurement"
            placeholder="e.g. pcs, box, kg"
            :error="form.errors.unit"
            required
          />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <FormInput
            v-model="form.stock"
            name="stock"
            label="Current Stock"
            type="number"
            :error="form.errors.stock"
            required
          />

          <FormInput
            v-model="form.minimum_stock"
            name="minimum_stock"
            label="Minimum Safety Stock"
            type="number"
            :error="form.errors.minimum_stock"
            required
          />
        </div>

        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          placeholder="Select status"
          :options="[
            { label: 'Active', value: 'active' },
            { label: 'Inactive', value: 'inactive' },
            { label: 'Archived', value: 'archived' }
          ]"
          :error="form.errors.status"
          required
        />

        <div class="space-y-1.5">
          <label for="description" class="text-sm font-medium text-gray-700">Description</label>
          <textarea
            id="description"
            v-model="form.description"
            rows="3"
            class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
          ></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
          <Link :href="route('inventory.items.index')" class="text-sm font-semibold leading-6 text-gray-900">
            Cancel
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
          >
            Update Item
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
