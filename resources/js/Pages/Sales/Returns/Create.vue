<!-- Create Return Request Form (§3J) -->
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
  description: string
  qty_ordered: number
  qty_delivered: number
}

interface SalesOrder {
  id: number
  so_number: string
  customer_id: number
  customer?: { name: string }
  lines: SalesOrderLine[]
}

const props = defineProps<{
  selectedOrder: SalesOrder | null
  customers: Array<{ id: number; name: string }>
  orders: Array<{ id: number; so_number: string; customer_id: number }>
}>()

const form = useForm({
  customer_id: props.selectedOrder?.customer_id ?? null as number | null,
  so_hdr_id: props.selectedOrder?.id ?? null as number | null,
  reason_code: '',
  lines: props.selectedOrder ? props.selectedOrder.lines.map(l => ({
    so_line_id: l.id,
    description: l.description,
    qty_delivered: Number(l.qty_delivered),
    qty_returned: 1,
    condition_notes: '',
  })) : [
    {
      so_line_id: null as number | null,
      description: '',
      qty_delivered: 0,
      qty_returned: 1,
      condition_notes: '',
    },
  ],
})

const onOrderChange = (soId: number) => {
  router.get(route('sales.returns.create'), { so_hdr_id: soId }, { preserveState: false })
}

const addLine = () => {
  form.lines.push({
    so_line_id: null,
    description: '',
    qty_delivered: 0,
    qty_returned: 1,
    condition_notes: '',
  })
}

const removeLine = (index: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(index, 1)
  }
}

const submit = () => {
  form.post(route('sales.returns.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Sales Return (RMA)"
      description="Initiate merchandise return request from customer (§3J)."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Return Authorization Details">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <FormSelect
                label="Customer *"
                v-model="form.customer_id"
                :error="form.errors.customer_id"
                :options="props.customers.map(c => ({ value: c.id, label: c.name }))"
                placeholder="Select customer…"
                required
              />
            </div>

            <div>
              <label class="block text-xs font-medium text-ink-700 mb-1">Original Sales Order (Optional)</label>
              <select
                :value="form.so_hdr_id"
                @change="onOrderChange(Number(($event.target as HTMLSelectElement).value))"
                class="w-full rounded-md border border-border bg-surface-0 py-2 px-3 text-sm text-ink-900 focus:border-accent focus:outline-none"
              >
                <option :value="null">-- Select order if applicable --</option>
                <option v-for="o in props.orders" :key="o.id" :value="o.id">
                  {{ o.so_number }}
                </option>
              </select>
            </div>

            <div>
              <FormInput
                label="Reason Code / Category *"
                v-model="form.reason_code"
                :error="form.errors.reason_code"
                placeholder="e.g. DEFECTIVE, WRONG_ITEM, DISSATISFIED"
                required
              />
            </div>
          </div>
        </Panel>

        <!-- Line Items -->
        <Panel title="Returned Items">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3">Description</th>
                  <th class="py-2 px-3 w-32">Qty to Return *</th>
                  <th class="py-2 px-3">Condition / Notes</th>
                  <th class="py-2 w-12 text-center"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td class="py-2 px-3">
                    <input
                      v-model="line.description"
                      type="text"
                      placeholder="Item description…"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 px-3">
                    <input
                      v-model.number="line.qty_returned"
                      type="number"
                      step="any"
                      min="0.001"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none font-mono"
                      required
                    />
                  </td>
                  <td class="py-2 px-3">
                    <input
                      v-model="line.condition_notes"
                      type="text"
                      placeholder="e.g. Damaged seal, wrong size"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                    />
                  </td>
                  <td class="py-2 text-center">
                    <button
                      type="button"
                      @click="removeLine(idx)"
                      class="text-rose-500 hover:text-rose-700 text-lg font-bold"
                    >
                      &times;
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 border-t border-border pt-4">
            <button
              type="button"
              @click="addLine"
              class="rounded-md border border-border px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-surface-100"
            >
              + Add Item Line
            </button>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.returns.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Submit Return Request</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
