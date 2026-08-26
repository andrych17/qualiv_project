<!-- Purchase Goods Receipt Create (§3E) -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface PoLine {
  id: number
  line_no: number
  description: string
  qty_ordered: number
  qty_received: number
  remaining_qty: number
  unit_price: number
}

interface EligiblePo {
  id: number
  po_no: string
  supplier_name: string | null
  status: string
  lines: PoLine[]
}

interface UserItem {
  id: number
  name: string
  email: string
}

interface ReceiptLineInput {
  po_line_id: number
  description: string
  qty_ordered: number
  qty_received_previously: number
  remaining_qty: number
  quantity_received: number
  unit_cost: number
  condition_notes: string
}

const props = defineProps<{
  eligibleOrders: EligiblePo[]
  initialPoId: number | null
  users: UserItem[]
}>()

const scanInput = ref('')

const form = useForm({
  po_id: props.initialPoId ?? (props.eligibleOrders.length > 0 ? props.eligibleOrders[0].id : null as number | null),
  receiver_id: null as number | null,
  received_at: new Date().toISOString().slice(0, 16),
  warehouse_id: null as number | null,
  location_id: null as number | null,
  discrepancy_notes: '',
  lines: [] as ReceiptLineInput[],
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
    qty_received_previously: l.qty_received,
    remaining_qty: l.remaining_qty,
    quantity_received: l.remaining_qty > 0 ? l.remaining_qty : 0,
    unit_cost: l.unit_price,
    condition_notes: '',
  }))
}

watch(() => form.po_id, () => {
  loadPoLines()
}, { immediate: true })

// Barcode / QR scan handler (§3E)
const handleScan = () => {
  const code = scanInput.value.trim()
  if (!code) return

  // Check if code matches a PO number
  const matchedPo = props.eligibleOrders.find((po) => po.po_no.toLowerCase() === code.toLowerCase())
  if (matchedPo) {
    form.po_id = matchedPo.id
    scanInput.value = ''
    return
  }

  // Check if code matches any line description
  if (form.lines.length > 0) {
    const matchedLine = form.lines.find((l) => l.description.toLowerCase().includes(code.toLowerCase()))
    if (matchedLine) {
      matchedLine.quantity_received += 1
      scanInput.value = ''
    }
  }
}

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val)
}

const submit = () => form.post(route('purchase.receipts.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Receive Goods (GR)" description="Record intake of physical items against a purchase order (§3E).">
      <template #actions>
        <SecondaryButton :href="route('purchase.receipts.index')">Cancel</SecondaryButton>
      </template>
    </PageHeader>

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <Panel title="Receipt Header">
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

          <FormSelect
            v-model="form.receiver_id"
            name="receiver_id"
            label="Receiver (User)"
            placeholder="Defaults to current user"
            :options="users.map((u) => ({ label: `${u.name} (${u.email})`, value: u.id }))"
            :error="form.errors.receiver_id"
          />

          <FormInput
            v-model="form.received_at"
            name="received_at"
            type="datetime-local"
            label="Received Date / Time"
            :error="form.errors.received_at"
          />
        </div>

        <!-- Quick Scan Barcode Input (§3E) -->
        <div class="mt-4 p-3 bg-surface-elevated border border-border rounded-md flex items-center gap-3">
          <span class="text-sm font-medium text-ink-700 whitespace-nowrap">📷 Scan Barcode / QR:</span>
          <input
            v-model="scanInput"
            type="text"
            placeholder="Scan PO Number or Item barcode to pre-fill…"
            class="flex-1 rounded-md border border-border text-sm py-1.5 px-3 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
            @keydown.enter.prevent="handleScan"
          />
          <SecondaryButton type="button" @click="handleScan">Scan / Search</SecondaryButton>
        </div>

        <div class="mt-4">
          <FormTextarea
            v-model="form.discrepancy_notes"
            name="discrepancy_notes"
            label="Discrepancy / Intake Notes"
            placeholder="Condition of shipment, carrier info, damaged packaging, or discrepancy details…"
            :rows="2"
            :error="form.errors.discrepancy_notes"
          />
        </div>
      </Panel>

      <Panel title="Received Line Items">
        <div v-if="form.lines.length > 0" class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-sm">
            <thead>
              <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
                <th class="py-2.5 px-3">Item Description</th>
                <th class="py-2.5 px-3 w-28 text-right">Ordered</th>
                <th class="py-2.5 px-3 w-28 text-right">Prev. Received</th>
                <th class="py-2.5 px-3 w-28 text-right">Remaining</th>
                <th class="py-2.5 px-3 w-36 text-right">Qty Received *</th>
                <th class="py-2.5 px-3 w-36 text-right">Unit Cost</th>
                <th class="py-2.5 px-3 w-64">Condition Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border/60">
              <tr v-for="(line, idx) in form.lines" :key="line.po_line_id" class="align-top">
                <td class="py-3 px-3">
                  <div class="font-medium text-ink-900">{{ line.description }}</div>
                </td>
                <td class="py-3 px-3 text-right text-ink-700">
                  {{ line.qty_ordered }}
                </td>
                <td class="py-3 px-3 text-right text-ink-600">
                  {{ line.qty_received_previously }}
                </td>
                <td class="py-3 px-3 text-right font-medium" :class="line.remaining_qty > 0 ? 'text-accent' : 'text-ink-400'">
                  {{ line.remaining_qty }}
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.quantity_received"
                    type="number"
                    step="any"
                    min="0"
                    required
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                  <div v-if="line.quantity_received > line.remaining_qty" class="text-xs text-amber-600 mt-0.5">
                    ⚠ Over-receipt
                  </div>
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model.number="line.unit_cost"
                    type="number"
                    step="0.01"
                    min="0"
                    class="w-full text-right rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
                <td class="py-2 px-2">
                  <input
                    v-model="line.condition_notes"
                    type="text"
                    placeholder="e.g. Good condition / Seal intact"
                    class="w-full rounded-md border border-border text-sm py-1.5 px-2 bg-surface text-ink-900 focus:border-accent focus:ring-1 focus:ring-accent"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="text-sm text-ink-500 py-4 text-center">
          Select a Purchase Order above to load line items for receipt.
        </div>
      </Panel>

      <div class="flex justify-end gap-3">
        <SecondaryButton :href="route('purchase.receipts.index')">Cancel</SecondaryButton>
        <PrimaryButton type="submit" :disabled="form.processing || form.lines.length === 0">
          Post Goods Receipt
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
