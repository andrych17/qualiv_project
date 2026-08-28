<!-- ponytail: Simple Inventory creation form using Inertia useForm helper -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

defineProps<{
  categories: Array<{ label: string; value: string | number }>
}>()

const form = useForm({
  code: '',
  name: '',
  description: '',
  inventory_category_id: null as number | null,
  stock: 0,
  minimum_stock: 0,
  unit: '',
  status: 'active',
})

const submit = () => {
  form.post(route('inventory.items.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Inventory Item"
      description="Add a new item to stock tracking."
    />

    <Panel class="mt-6 max-w-2xl">
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

        <FormTextarea
          v-model="form.description"
          name="description"
          label="Description"
          placeholder="Optional item description..."
          :rows="3"
          :error="form.errors.description"
        />

        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
          <Link :href="route('inventory.items.index')">
            <SecondaryButton type="button">
              Cancel
            </SecondaryButton>
          </Link>
          <PrimaryButton
            type="submit"
            :disabled="form.processing"
          >
            Save Item
          </PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
