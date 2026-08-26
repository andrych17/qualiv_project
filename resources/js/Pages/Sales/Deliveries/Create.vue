<!-- Create Delivery Form (§3H) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
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
  so_hdr_id: props.selectedOrder?.id ?? null as number | null,
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

const onOrderChange = (soId: number) => {
  router.get(route('sales.deliveries.create'), { so_hdr_id: soId }, { preserveState: false })
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
              <label class="block text-xs font-medium text-ink-700 mb-1">Select Sales Order *</label>
              <select
                :value="form.so_hdr_id"
                @change="onOrderChange(Number(($event.target as HTMLSelectElement).value))"
                class="w-full rounded-md border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:border-accent focus:outline-none"
                required
              >
                <option :value="null">-- Select confirmed order --</option>
                <option v-for="o in props.confirmedOrders" :key="o.id" :value="o.id">
                  {{ o.so_number }} ({{ o.customer?.name ?? 'Customer' }})
                </option>
              </select>
            </div>

            <div>
              <FormInput
                label="Carrier Name"
                v-model="form.carrier"
                :error="form.errors.carrier"
                placeholder="e.g. JNE, SiCepat, Internal Fleet"
              />
            </div>

            <div>
              <FormInput
                label="Tracking Number / AWB"
                v-model="form.tracking_number"
                :error="form.errors.tracking_number"
                placeholder="e.g. JNE-123456789"
              />
            </div>
          </div>
        </Panel>

        <!-- Lines -->
        <Panel v-if="form.lines.length > 0" title="Items to Ship">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3">Description</th>
                  <th class="py-2 px-3 text-right">Ordered</th>
                  <th class="py-2 px-3 text-right">Already Delivered</th>
                  <th class="py-2 px-3 text-right">Remaining</th>
                  <th class="py-2 px-3 text-right w-36">Qty This Delivery *</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(line, idx) in form.lines" :key="line.so_line_id">
                  <td class="py-2.5 px-3 font-medium text-ink-900">{{ line.description }}</td>
                  <td class="py-2.5 px-3 text-right font-mono text-ink-600">{{ line.qty_ordered }}</td>
                  <td class="py-2.5 px-3 text-right font-mono text-ink-600">{{ line.qty_delivered }}</td>
                  <td class="py-2.5 px-3 text-right font-mono font-semibold text-accent">
                    {{ line.qty_ordered - line.qty_delivered }}
                  </td>
                  <td class="py-2.5 px-3 text-right">
                    <input
                      v-model.number="line.qty_shipped"
                      type="number"
                      step="any"
                      min="0"
                      :max="line.qty_ordered - line.qty_delivered"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-right font-mono text-ink-900 focus:outline-none"
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
          <PrimaryButton type="submit" :disabled="form.processing || form.lines.length === 0">
            Create Delivery Note
          </PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
