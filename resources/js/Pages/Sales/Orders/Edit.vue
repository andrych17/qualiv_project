<!-- Edit Sales Order Form (§3F) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

interface SalesOrderLine {
  id?: number
  item_type: 'product' | 'service'
  product_id: number | null
  description: string
  qty_ordered: number
  unit_price: number
  discount_amount: number
  tax_amount: number
}

interface SalesOrderItem {
  id: number
  so_number: string
  status: string
  customer_id: number
  price_list_id: number | null
  lines: SalesOrderLine[]
}

const props = defineProps<{
  order: SalesOrderItem
  customers: Array<{ id: number; name: string }>
  priceLists: Array<{ id: number; name: string }>
}>()

const form = useForm({
  customer_id: props.order.customer_id,
  price_list_id: props.order.price_list_id,
  lines: props.order.lines.map(l => ({
    item_type: l.item_type,
    product_id: l.product_id,
    description: l.description,
    qty_ordered: Number(l.qty_ordered),
    unit_price: Number(l.unit_price),
    discount_amount: Number(l.discount_amount),
    tax_amount: Number(l.tax_amount),
  })),
})

const addLine = () => {
  form.lines.push({
    item_type: 'service',
    product_id: null,
    description: '',
    qty_ordered: 1,
    unit_price: 0,
    discount_amount: 0,
    tax_amount: 0,
  })
}

const removeLine = (index: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(index, 1)
  }
}

const calculateSubtotal = () => {
  return form.lines.reduce((sum, l) => sum + (Number(l.qty_ordered) * Number(l.unit_price)), 0)
}

const calculateDiscount = () => {
  return form.lines.reduce((sum, l) => sum + Number(l.discount_amount), 0)
}

const calculateTax = () => {
  return form.lines.reduce((sum, l) => sum + Number(l.tax_amount), 0)
}

const calculateTotal = () => {
  return calculateSubtotal() - calculateDiscount() + calculateTax()
}

const submit = () => {
  form.put(route('sales.orders.update', props.order.id))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Edit Sales Order ${props.order.so_number}`"
      description="Update draft sales order lines."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Order Header">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <FormSelect
                label="Customer / Client"
                name="customer_id"
                v-model="form.customer_id"
                :error="form.errors.customer_id"
                :options="props.customers.map(c => ({ value: c.id, label: c.name }))"
                placeholder="Select customer…"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Price List (Optional)"
                name="price_list_id"
                v-model="form.price_list_id"
                :error="form.errors.price_list_id"
                :options="props.priceLists.map(p => ({ value: p.id, label: p.name }))"
                placeholder="Default price list…"
              />
            </div>
          </div>
        </Panel>

        <!-- Order Line Items -->
        <Panel title="Order Line Items">
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
              <thead class="text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 w-28">Type</th>
                  <th class="py-2">Description</th>
                  <th class="py-2 w-24">Qty</th>
                  <th class="py-2 w-32">Unit Price</th>
                  <th class="py-2 w-28">Discount</th>
                  <th class="py-2 w-28">Tax</th>
                  <th class="py-2 w-32 text-right">Line Total</th>
                  <th class="py-2 w-12 text-center"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(line, idx) in form.lines" :key="idx">
                  <td class="py-2 pr-2">
                    <select
                      v-model="line.item_type"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-xs text-ink-900 focus:outline-none"
                    >
                      <option value="service">Service</option>
                      <option value="product">Product</option>
                    </select>
                  </td>
                  <td class="py-2 pr-2">
                    <input
                      v-model="line.description"
                      type="text"
                      placeholder="Item description…"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <input
                      v-model.number="line.qty_ordered"
                      type="number"
                      step="any"
                      min="0.001"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <input
                      v-model.number="line.unit_price"
                      type="number"
                      step="any"
                      min="0"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                      required
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <input
                      v-model.number="line.discount_amount"
                      type="number"
                      step="any"
                      min="0"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <input
                      v-model.number="line.tax_amount"
                      type="number"
                      step="any"
                      min="0"
                      class="w-full rounded border border-border bg-surface-0 py-1.5 px-2 text-sm text-ink-900 focus:outline-none"
                    />
                  </td>
                  <td class="py-2 text-right font-mono font-medium text-ink-900">
                    {{ formatCurrency((line.qty_ordered * line.unit_price) - (line.discount_amount || 0) + (line.tax_amount || 0)) }}
                  </td>
                  <td class="py-2 text-center">
                    <button
                      type="button"
                      @click="removeLine(idx)"
                      class="text-signal-danger hover:underline text-base font-bold"
                      title="Remove line"
                    >
                      &times;
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-4 flex items-center justify-between border-t border-border pt-4">
            <SecondaryButton type="button" @click="addLine">
              + Add Line Item
            </SecondaryButton>

            <div class="w-64 space-y-1 text-sm">
              <div class="flex justify-between text-ink-600">
                <span>Subtotal:</span>
                <span class="font-mono">{{ formatCurrency(calculateSubtotal()) }}</span>
              </div>
              <div class="flex justify-between text-ink-600">
                <span>Total Discount:</span>
                <span class="font-mono text-signal-success">- {{ formatCurrency(calculateDiscount()) }}</span>
              </div>
              <div class="flex justify-between text-ink-600">
                <span>Total Tax:</span>
                <span class="font-mono">+ {{ formatCurrency(calculateTax()) }}</span>
              </div>
              <div class="flex justify-between font-bold text-ink-900 pt-2 border-t border-border">
                <span>Total Amount:</span>
                <span class="font-mono text-base">{{ formatCurrency(calculateTotal()) }}</span>
              </div>
            </div>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.orders.show', props.order.id)">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Update Sales Order</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
