<!-- Purchase Order Create (§3D) -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface Partner {
  id: number
  name: string
  type: string
}

interface Category {
  id: number
  name: string
  kind: string
  capex_opex: string
}

interface CatalogItem {
  id: number
  item_code: string
  description: string
  negotiated_price: number | null
  category_id: number | null
  unit: string
  preferred_supplier_id: number | null
}

interface PrSummary {
  id: number
  pr_no: string
  estimated_total: number
}

interface LineItem {
  catalog_item_id: number | null
  description: string
  qty_ordered: number
  unit_price: number
  tax_amount: number
  expected_delivery_date: string
  category_id: number | null
  local_content_pct: number | null
}

const props = defineProps<{
  eligiblePartners: Partner[]
  categories: Category[]
  catalogItems: CatalogItem[]
  approvedPrs: PrSummary[]
  initialPr?: {
    id: number
    pr_no: string
    needed_by: string | null
    lines: Array<{
      catalog_item_id: number | null
      description: string
      qty_ordered: number
      unit_price: number
      tax_amount: number
      category_id: number | null
      local_content_pct: number | null
    }>
  } | null
}>()

const form = useForm({
  supplier_id: null as number | null,
  pr_id: props.initialPr?.id ?? null as number | null,
  ship_to: '',
  bill_to: '',
  currency_code: 'IDR',
  incoterms: '',
  payment_terms_days: 30,
  expected_delivery_date: props.initialPr?.needed_by ?? '',
  lines: (props.initialPr?.lines && props.initialPr.lines.length > 0
    ? props.initialPr.lines.map((l) => ({
        ...l,
        expected_delivery_date: props.initialPr?.needed_by ?? '',
      }))
    : [
        {
          catalog_item_id: null,
          description: '',
          qty_ordered: 1,
          unit_price: 0,
          tax_amount: 0,
          expected_delivery_date: '',
          category_id: null,
          local_content_pct: null,
        },
      ]) as LineItem[],
})

const onCatalogItemChange = (index: number) => {
  const line = form.lines[index]
  if (line.catalog_item_id) {
    const item = props.catalogItems.find((c) => c.id === Number(line.catalog_item_id))
    if (item) {
      line.description = item.description
      if (item.negotiated_price) {
        line.unit_price = Number(item.negotiated_price)
      }
      if (item.category_id) {
        line.category_id = item.category_id
      }
      if (!form.supplier_id && item.preferred_supplier_id) {
        form.supplier_id = item.preferred_supplier_id
      }
    }
  }
}

const addLine = () => {
  form.lines.push({
    catalog_item_id: null,
    description: '',
    qty_ordered: 1,
    unit_price: 0,
    tax_amount: 0,
    expected_delivery_date: form.expected_delivery_date,
    category_id: null,
    local_content_pct: null,
  })
}

const removeLine = (index: number) => {
  if (form.lines.length > 1) {
    form.lines.splice(index, 1)
  }
}

const subtotal = computed(() => {
  return form.lines.reduce((sum, line) => {
    return sum + (Number(line.qty_ordered) || 0) * (Number(line.unit_price) || 0)
  }, 0)
})

const totalTax = computed(() => {
  return form.lines.reduce((sum, line) => sum + (Number(line.tax_amount) || 0), 0)
})

const totalAmount = computed(() => subtotal.value + totalTax.value)

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: form.currency_code || 'IDR', maximumFractionDigits: 0 }).format(val)
}

const submit = () => form.post(route('purchase.orders.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Purchase Order" description="Create a vendor purchase order (§3D).">
      <template #actions>
        <SecondaryButton :href="route('purchase.orders.index')">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <Panel title="Order Header">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <FormSelect
            v-model="form.supplier_id"
            name="supplier_id"
            label="Supplier / Vendor *"
            placeholder="Select vendor"
            :options="eligiblePartners.map((p) => ({ label: p.name, value: p.id }))"
            :error="form.errors.supplier_id"
            required
          />

          <FormSelect
            v-model="form.pr_id"
            name="pr_id"
            label="Linked Requisition (PR)"
            placeholder="Standalone PO (or select PR)"
            :options="approvedPrs.map((pr) => ({ label: `${pr.pr_no} (${formatCurrency(pr.estimated_total)})`, value: pr.id }))"
            :error="form.errors.pr_id"
          />

          <FormInput
            v-model="form.expected_delivery_date"
            name="expected_delivery_date"
            type="date"
            label="Expected Delivery Date"
            :error="form.errors.expected_delivery_date"
          />

          <FormInput
            v-model="form.payment_terms_days"
            name="payment_terms_days"
            type="number"
            label="Payment Terms (Days)"
            :error="form.errors.payment_terms_days"
          />

          <FormInput
            v-model="form.currency_code"
            name="currency_code"
            label="Currency"
            placeholder="IDR"
            :error="form.errors.currency_code"
          />

          <FormInput
            v-model="form.incoterms"
            name="incoterms"
            label="Incoterms"
            placeholder="e.g. FOB, CIF, DDP"
            :error="form.errors.incoterms"
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormInput
            v-model="form.ship_to"
            name="ship_to"
            label="Ship-To Address"
            placeholder="Warehouse or delivery location"
            :error="form.errors.ship_to"
          />

          <FormInput
            v-model="form.bill_to"
            name="bill_to"
            label="Bill-To Address"
            placeholder="Billing legal entity address"
            :error="form.errors.bill_to"
          />
        </div>
      </Panel>

      <Panel title="Line Items">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border">
            <thead>
              <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
                <th class="py-2 px-3 w-48">Catalog Item</th>
                <th class="py-2 px-3">Description *</th>
                <th class="py-2 px-3 w-28 text-right">Qty *</th>
                <th class="py-2 px-3 w-36 text-right">Unit Price *</th>
                <th class="py-2 px-3 w-28 text-right">Tax Amt</th>
                <th class="py-2 px-3 w-40">Category</th>
                <th class="py-2 px-3 w-24 text-right">TKDN %</th>
                <th class="py-2 px-3 w-36 text-right">Total</th>
                <th class="py-2 px-3 w-12"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(line, idx) in form.lines" :key="idx" class="align-top">
                <td class="py-2 px-2">
                  <select
                    v-model="line.catalog_item_id"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                    @change="onCatalogItemChange(idx)"
                  >
                    <option :value="null">Custom item</option>
                    <option v-for="cat in catalogItems" :key="cat.id" :value="cat.id">
                      {{ cat.item_code }} - {{ cat.description }}
                    </option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="line.description"
                    type="text"
                    required
                    placeholder="Description"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                  <div v-if="form.errors[`lines.${idx}.description`]" class="text-xs text-rose-600 mt-0.5">
                    {{ form.errors[`lines.${idx}.description`] }}
                  </div>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.qty_ordered"
                    type="number"
                    step="any"
                    min="0.0001"
                    required
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.unit_price"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.tax_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <select
                    v-model="line.category_id"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  >
                    <option :value="null">Select category</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">
                      {{ c.name }}
                    </option>
                  </select>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.local_content_pct"
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    placeholder="%"
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2 text-right text-sm font-medium text-ink-900 pt-3">
                  {{ formatCurrency(((Number(line.qty_ordered) || 0) * (Number(line.unit_price) || 0)) + (Number(line.tax_amount) || 0)) }}
                </td>
                <td class="py-2 px-2 text-center pt-2">
                  <button
                    type="button"
                    class="text-ink-400 hover:text-rose-600 transition"
                    title="Remove line"
                    @click="removeLine(idx)"
                    :disabled="form.lines.length <= 1"
                  >
                    ✕
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col md:flex-row items-start md:items-center justify-between border-t border-border pt-4 gap-4">
          <SecondaryButton type="button" @click="addLine">+ Add Line</SecondaryButton>
          <div class="space-y-1 text-right w-full md:w-auto">
            <div class="text-sm text-ink-600 flex justify-between md:justify-end gap-6">
              <span>Subtotal:</span>
              <span class="font-medium text-ink-900">{{ formatCurrency(subtotal) }}</span>
            </div>
            <div class="text-sm text-ink-600 flex justify-between md:justify-end gap-6">
              <span>Tax Amount:</span>
              <span class="font-medium text-ink-900">{{ formatCurrency(totalTax) }}</span>
            </div>
            <div class="text-base font-bold text-ink-900 flex justify-between md:justify-end gap-6 border-t border-border pt-1">
              <span>Total Amount:</span>
              <span class="text-lg text-accent">{{ formatCurrency(totalAmount) }}</span>
            </div>
          </div>
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.orders.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing">Save Purchase Order</PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
