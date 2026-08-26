<!-- Create Quotation Form (§3E) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

interface PriceListLine {
  item_type: 'product' | 'service'
  product_id: number | null
  description: string
  unit_price: number
}

interface PriceList {
  id: number
  name: string
  lines?: PriceListLine[]
}

const props = defineProps<{
  customers: Array<{ id: number; name: string }>
  opportunities: Array<{ id: number; name: string; customer_id: number | null }>
  priceLists: PriceList[]
  preselectedCustomerId: number | null
  preselectedOpportunityId: number | null
}>()

const form = useForm({
  customer_id: props.preselectedCustomerId,
  opportunity_id: props.preselectedOpportunityId,
  price_list_id: null as number | null,
  validity_date: null as string | null,
  subject_type: null as string | null,
  subject_id: null as number | null,
  lines: [
    {
      item_type: 'service',
      product_id: null as number | null,
      description: '',
      quantity: 1,
      unit_price: 0,
      discount_amount: 0,
      tax_amount: 0,
    },
  ],
})

const addLine = () => {
  form.lines.push({
    item_type: 'service',
    product_id: null,
    description: '',
    quantity: 1,
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
  return form.lines.reduce((sum, l) => sum + (Number(l.quantity) * Number(l.unit_price)), 0)
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
  form.post(route('sales.quotations.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Quotation"
      description="Prepare a new quotation estimate for a client."
    />

    <div class="mt-6">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Quotation Header">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
                label="Opportunity (Optional)"
                name="opportunity_id"
                v-model="form.opportunity_id"
                :error="form.errors.opportunity_id"
                :options="props.opportunities.map(o => ({ value: o.id, label: o.name }))"
                placeholder="Link to opportunity…"
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

            <div>
              <FormInput
                label="Validity Expiration Date"
                name="validity_date"
                type="date"
                v-model="form.validity_date"
                :error="form.errors.validity_date"
              />
            </div>
          </div>
        </Panel>

        <!-- Line Items -->
        <Panel title="Quotation Line Items">
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
                    <FormNumberInput
                      v-model="line.quantity"
                      :decimals="2"
                      placeholder="1"
                      required
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <FormCurrencyInput
                      v-model="line.unit_price"
                      prefix=""
                      placeholder="0"
                      required
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <FormCurrencyInput
                      v-model="line.discount_amount"
                      prefix=""
                      placeholder="0"
                    />
                  </td>
                  <td class="py-2 pr-2">
                    <FormCurrencyInput
                      v-model="line.tax_amount"
                      prefix=""
                      placeholder="0"
                    />
                  </td>
                  <td class="py-2 text-right font-mono font-medium text-ink-900">
                    {{ formatCurrency((line.quantity * line.unit_price) - (line.discount_amount || 0) + (line.tax_amount || 0)) }}
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
          <SecondaryButton :href="route('sales.quotations.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Quotation Draft</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
