<!-- ponytail: Edit Production Order (MES_SPECS.md §3A) — draft only -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  order: {
    id: number
    order_number: string
    product_label: string | null
    production_model: string
    qty: number
    uom_code: string | null
    planned_start: string | null
    planned_end: string | null
    priority: string
    warehouse_id: number | null
    line_area: string | null
  }
  warehouses: Array<{ value: number; label: string }>
}>()

const priorityOptions = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const form = useForm({
  qty: props.order.qty,
  uom_code: props.order.uom_code ?? '',
  planned_start: props.order.planned_start ?? '',
  planned_end: props.order.planned_end ?? '',
  priority: props.order.priority,
  warehouse_id: props.order.warehouse_id,
  line_area: props.order.line_area ?? '',
})

const submit = () => form.put(route('mes.prodOrders.update', props.order.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit Production Order" :description="order.order_number" />

    <Panel class="mt-6 max-w-2xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
          <FormInput :model-value="order.product_label ?? ''" name="product_label" label="Product" disabled />
          <FormInput :model-value="order.production_model" name="production_model" label="Production Model" disabled />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormNumberInput v-model="form.qty" name="qty" label="Quantity" :decimals="4" :error="form.errors.qty" required />
          <FormInput v-model="form.uom_code" name="uom_code" label="UoM code" placeholder="e.g. EA, KG" :error="form.errors.uom_code" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.planned_start" name="planned_start" label="Planned Start" type="datetime-local" :error="form.errors.planned_start" />
          <FormInput v-model="form.planned_end" name="planned_end" label="Planned End" type="datetime-local" :error="form.errors.planned_end" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormSelect v-model="form.priority" name="priority" label="Priority" :options="priorityOptions" :error="form.errors.priority" />
          <FormSelect v-model="form.warehouse_id" name="warehouse_id" label="Warehouse" :options="warehouses" :error="form.errors.warehouse_id" />
        </div>

        <FormInput v-model="form.line_area" name="line_area" label="Production Line / Area" placeholder="e.g. Assembly Line A" :error="form.errors.line_area" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('mes.prodOrders.show', order.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save Production Order</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
