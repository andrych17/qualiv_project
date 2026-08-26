<!-- Create Delivery Form (§3H) -->
<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface SalesOrderLine {
  id: number
  line_no: number
  description: string
  qty_ordered: number
  qty_delivered: number
}

interface SalesOrder {
  id: number
  so_number: string
  customer?: { name: string }
  lines: SalesOrderLine[]
}

const props = defineProps<{
  selectedOrder: SalesOrder | null
  confirmedOrders: Array<{ id: number; so_number: string; customer?: { name: string } }>
}>()

const form = useForm({
  so_hdr_id: props.selectedOrder?.id ?? (null as number | null),
  carrier: '',
  tracking_number: '',
  source_location_id: null as number | null,
  lines: props.selectedOrder ? props.selectedOrder.lines.map(l => ({
    so_line_id: l.id,
    description: l.description,
    qty_ordered: Number(l.qty_ordered),
    qty_delivered: Number(l.qty_delivered),
    qty_shipped: Math.max(0, Number(l.qty_ordered) - Number(l.qty_delivered)),
  })) : [],
})

const onOrderChange = (soId: string | number | null) => {
  if (soId) {
    router.get(route('sales.deliveries.create'), { so_hdr_id: Number(soId) }, { preserveState: false })
  }
}

const submit = () => {
  form.post(route('sales.deliveries.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Delivery Note"
      description="Prepare goods or service fulfillment delivery (§3H)."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Delivery Information">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormSelect
                label="Select Sales Order"
                name="so_hdr_id"
                :model-value="form.so_hdr_id"
                @update:model-value="onOrderChange"
                :options="props.confirmedOrders.map(o => ({ value: o.id, label: `${o.so_number} (${o.customer?.name ?? 'Customer'})` }))"
                placeholder="Select confirmed order…"
                required
              />
            </div>

            <div>
              <FormInput
                label="Carrier Name"
                name="carrier"
                v-model="form.carrier"
                :error="form.errors.carrier"
                placeholder="e.g. JNE, SiCepat, Internal Fleet"
              />
            </div>

            <div>
              <FormInput
                label="Tracking Number / AWB"
                name="tracking_number"
                v-model="form.tracking_number"
                :error="form.errors.tracking_number"
                placeholder="e.g. JNE-123456789"
              />
            </div>
          </div>
        </Panel>

        <!-- Delivery Lines Table -->
        <Panel v-if="props.selectedOrder" title="Items to Ship">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2">Description</th>
                  <th class="py-2 w-32">Qty Ordered</th>
                  <th class="py-2 w-32">Qty Delivered</th>
                  <th class="py-2 w-36">Qty to Ship *</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td class="py-3 pr-2 font-medium text-ink-900">{{ line.description }}</td>
                  <td class="py-3 pr-2 font-mono text-ink-600">{{ line.qty_ordered }}</td>
                  <td class="py-3 pr-2 font-mono text-ink-600">{{ line.qty_delivered }}</td>
                  <td class="py-3 pr-2">
                    <input
                      v-model.number="line.qty_shipped"
                      type="number"
                      step="any"
                      min="0.001"
                      :max="line.qty_ordered - line.qty_delivered"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.deliveries.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing || !props.selectedOrder">
            Create Delivery Note
          </PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
