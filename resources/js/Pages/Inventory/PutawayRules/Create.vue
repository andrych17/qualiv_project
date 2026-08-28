<!-- ponytail: New Put-away Rule (§3R) — condition is exactly one of product or category. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'

const props = defineProps<{
  warehouses: Array<{ id: number; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
  categories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  warehouse_id: null as number | null,
  condition_type: 'product' as 'product' | 'category',
  product_id: null as number | null,
  category_id: null as number | null,
  target_location_id: null as number | null,
  priority_order: 0,
})

const availableLocations = computed(() =>
  form.warehouse_id ? props.locations.filter((l) => l.warehouse_id === form.warehouse_id) : [],
)

watch(() => form.condition_type, () => {
  form.product_id = null
  form.category_id = null
})

const submit = () => form.post(route('inventory.putawayRules.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Put-away Rule" description="Applied as the default destination on Goods Receipt lines when left blank." />

    <InventorySubNav active="putawayRules" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSelect
          v-model="form.warehouse_id"
          name="warehouse_id"
          label="Warehouse"
          placeholder="Select a warehouse…"
          :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
          :error="form.errors.warehouse_id"
          required
        />

        <FormRadioGroup
          v-model="form.condition_type"
          name="condition_type"
          label="Condition"
          inline
          :options="[
            { label: 'Specific product', value: 'product' },
            { label: 'Category', value: 'category' },
          ]"
        />

        <FormAsyncSearchableSelect
          v-if="form.condition_type === 'product'"
          v-model="form.product_id"
          name="product_id"
          label="Product"
          api-entity="inventory_product"
          placeholder="Search SKU or name…"
          :error="form.errors.product_id"
          required
        />
        <FormSelect
          v-else
          v-model="form.category_id"
          name="category_id"
          label="Category"
          placeholder="Select a category…"
          :options="categories.map((c) => ({ label: c.name, value: c.id }))"
          :error="form.errors.category_id"
          required
        />

        <FormSelect
          v-model="form.target_location_id"
          name="target_location_id"
          label="Target location"
          placeholder="Select a location…"
          :options="availableLocations.map((l) => ({ label: l.code, value: l.id }))"
          :error="form.errors.target_location_id"
          required
        />

        <FormNumberInput
          v-model="form.priority_order"
          name="priority_order"
          label="Priority"
          :error="form.errors.priority_order"
        />
        <p class="text-xs text-ink-600">Lower number wins first — a specific-product rule usually beats a broader category rule.</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('inventory.putawayRules.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save rule</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
