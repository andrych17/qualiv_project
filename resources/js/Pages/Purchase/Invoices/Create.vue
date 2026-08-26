<!-- Purchase Invoice Capture Create (§3F) -->
<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface PoLine {
  id: number
  line_no: number
  description: string
  qty_ordered: number
  qty_received: number
  unit_price: number
}

interface EligiblePo {
  id: number
  po_no: string
  supplier_id: number
  supplier_name: string | null
  currency_code: string
  total_amount: number
  status: string
  lines: PoLine[]
}

interface InvoiceLineInput {
  po_line_id: number
  description: string
  qty_ordered: number
  qty_received: number
  qty: number
  unit_price: number
}

const props = defineProps<{
  eligibleOrders: EligiblePo[]
  initialPoId: number | null
}>()

const form = useForm({
  po_id: props.initialPoId ?? (props.eligibleOrders.length > 0 ? props.eligibleOrders[0].id : null as number | null),
  supplier_invoice_no: '',
  supplier_invoice_date: new Date().toISOString().slice(0, 10),
  submission_channel: 'manual',
  dms_document_id: null as number | null,
  lines: [] as InvoiceLineInput[],
})

const selectedPo = computed(() => {
  return props.eligibleOrders.find((po) => po.id === Number(form.po_id))
})

const loadPoLines = () => {
  const po = selectedPo.value
  if (!po) {
    form.lines = []
    return
  }

  form.lines = po.lines.map((l) => ({
    po_line_id: l.id,
    description: l.description,
    qty_ordered: l.qty_ordered,
    qty_received: l.qty_received,
    qty: l.qty_received > 0 ? l.qty_received : l.qty_ordered,
    unit_price: l.unit_price,
  }))
}

watch(() => form.po_id, () => {
  loadPoLines()
}, { immediate: true })

const computedTotal = computed(() => {
  return form.lines.reduce((acc, line) => acc + (Number(line.qty) * Number(line.unit_price)), 0)
})

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: selectedPo.value?.currency_code || 'IDR',
    maximumFractionDigits: 0,
  }).format(val)
}

const submit = () => form.post(route('purchase.invoices.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Capture Vendor Invoice" description="Enter supplier invoice details to perform automated three-way matching against PO and GR (§3F).">
      <template #actions>
        <SecondaryButton :href="route('purchase.invoices.index')">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <Panel title="Invoice Header">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <FormSelect
            v-model="form.po_id"
            name="po_id"
            label="Purchase Order *"
            placeholder="Select purchase order"
            :options="eligibleOrders.map((po) => ({
              label: `${po.po_no} — ${po.supplier_name ?? 'Unknown'} (${po.status})`,
              value: po.id,
            }))"
            :error="form.errors.po_id"
            required
          />

          <FormInput
            v-model="form.supplier_invoice_no"
            name="supplier_invoice_no"
            label="Supplier Invoice Number *"
            placeholder="e.g. INV-2026/08/9981"
            :error="form.errors.supplier_invoice_no"
            required
          />

          <FormInput
            v-model="form.supplier_invoice_date"
            name="supplier_invoice_date"
            type="date"
            label="Supplier Invoice Date *"
            :error="form.errors.supplier_invoice_date"
            required
          />
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm bg-surface-elevated p-3 rounded-md border border-border">
          <div>
            <span class="text-ink-500 font-medium">Supplier / Vendor:</span>
            <div class="font-semibold text-ink-900">{{ selectedPo?.supplier_name ?? '—' }}</div>
          </div>
          <div>
            <span class="text-ink-500 font-medium">Currency:</span>
            <div class="font-semibold text-ink-900">{{ selectedPo?.currency_code ?? 'IDR' }}</div>
          </div>
          <div>
            <span class="text-ink-500 font-medium">Total Invoiced Amount:</span>
            <div class="text-base font-bold text-accent">{{ formatCurrency(computedTotal) }}</div>
          </div>
        </div>
      </Panel>

      <Panel title="Invoice Line Items (Billed Quantities & Prices)">
        <div v-if="form.lines.length > 0" class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
                <th class="py-2.5 px-3">Item Description</th>
                <th class="py-2.5 px-3 w-28 text-right">PO Ordered</th>
                <th class="py-2.5 px-3 w-28 text-right">GR Received</th>
                <th class="py-2.5 px-3 w-36 text-right">Billed Qty *</th>
                <th class="py-2.5 px-3 w-40 text-right">Unit Price *</th>
                <th class="py-2.5 px-3 w-40 text-right">Line Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(line, idx) in form.lines" :key="line.po_line_id" class="align-middle">
                <td class="py-3 px-3">
                  <div class="font-medium text-ink-900">{{ line.description }}</div>
                </td>
                <td class="py-3 px-3 text-right text-ink-600">
                  {{ line.qty_ordered }}
                </td>
                <td class="py-3 px-3 text-right font-medium" :class="line.qty_received > 0 ? 'text-emerald-700' : 'text-amber-600'">
                  {{ line.qty_received }}
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.qty"
                    type="number"
                    step="any"
                    min="0"
                    required
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                  <div v-if="line.qty !== line.qty_received" class="text-xs text-rose-600 mt-0.5">
                    ⚠ Differs from GR ({{ line.qty_received }})
                  </div>
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
                <td class="py-3 px-3 text-right font-semibold text-ink-900">
                  {{ formatCurrency(line.qty * line.unit_price) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">
          Select a Purchase Order above to load line items.
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.invoices.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing || form.lines.length === 0">
          Capture & Run 3-Way Match
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
