<!-- Purchase Invoice Show & Three-Way Match Grid (§3F) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface InvoiceLineItem {
  id: number
  po_line_id: number
  description: string
  qty: number
  unit_price: number
  line_amount: number
}

interface MatchItem {
  id: number
  po_line_id: number
  description: string
  po_qty: number
  po_price: number
  gr_qty: number
  invoice_qty: number
  invoice_price: number
  qty_variance_pct: number
  price_variance_pct: number
  within_tolerance: boolean
}

const props = defineProps<{
  invoice: {
    id: number
    uuid: string
    supplier_invoice_no: string
    supplier_invoice_date: string | null
    po_id: number
    po_no: string | null
    supplier: { id: number; name: string } | null
    pr_no: string | null
    currency_code: string
    amount: number
    match_status: string
    status: string
    submission_channel: string
    ap_bill: { id: number; bill_no: string; status: string; amount: number } | null
    creator: { id: number; name: string } | null
    created_at: string | null
    lines: InvoiceLineItem[]
    matches: MatchItem[]
  }
}>()

const showRejectModal = ref(false)
const rejectForm = useForm({ reason: '' })

const rematch = () => {
  router.post(route('purchase.invoices.rematch', props.invoice.id))
}

const submitForApproval = () => {
  router.post(route('purchase.invoices.submit', props.invoice.id))
}

const approve = () => {
  router.post(route('purchase.invoices.approve', props.invoice.id))
}

const submitReject = () => {
  rejectForm.post(route('purchase.invoices.reject', props.invoice.id), {
    onSuccess: () => {
      showRejectModal.value = false
    },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Invoice ${invoice.supplier_invoice_no}`" description="Three-way match verification against PO commitment and physical Goods Receipts (§3F).">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('purchase.invoices.index')">Back to list</SecondaryButton>

          <!-- Recompute match -->
          <SecondaryButton @click="rematch">
            Recompute 3-Way Match
          </SecondaryButton>

          <!-- Captured: Submit for approval -->
          <PrimaryButton
            v-if="invoice.status === 'captured'"
            @click="submitForApproval"
          >
            Submit for approval
          </PrimaryButton>

          <!-- Pending Approval: Approve & Reject -->
          <PrimaryButton
            v-if="invoice.status === 'pending_approval'"
            @click="approve"
          >
            Approve & Send to AP
          </PrimaryButton>
          <DangerButton
            v-if="invoice.status === 'pending_approval'"
            @click="showRejectModal = true"
          >
            Reject
          </DangerButton>
        </div>
      </template>
    </PageHeader>

    <!-- Overview Details -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
      <Panel title="Invoice Overview" class="md:col-span-2">
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3 text-sm">
          <div>
            <dt class="text-ink-500 font-medium">Status</dt>
            <dd class="mt-1"><StatusBadge :status="invoice.status" /></dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">3-Way Match Status</dt>
            <dd class="mt-1">
              <span
                v-if="invoice.match_status === 'matched'"
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800"
              >
                ✓ Matched
              </span>
              <span
                v-else-if="invoice.match_status === 'mismatch'"
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800"
              >
                ✕ Mismatch Detected
              </span>
              <span
                v-else
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800"
              >
                Pending
              </span>
            </dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Total Billed Amount</dt>
            <dd class="mt-1 text-base font-bold text-accent">{{ formatCurrency(invoice.amount) }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Purchase Order</dt>
            <dd class="mt-1">
              <Link :href="route('purchase.orders.show', invoice.po_id)" class="text-accent font-semibold hover:underline">
                {{ invoice.po_no }}
              </Link>
            </dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Supplier / Vendor</dt>
            <dd class="mt-1 font-semibold text-ink-900">{{ invoice.supplier?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Invoice Date</dt>
            <dd class="mt-1 text-ink-900">{{ invoice.supplier_invoice_date ?? '—' }}</dd>
          </div>
        </dl>
      </Panel>

      <!-- Accounting AP Link Panel -->
      <Panel title="Accounting AP Integration (§3F)">
        <div v-if="invoice.ap_bill" class="space-y-2 text-sm">
          <div class="flex items-center justify-between">
            <span class="text-ink-500">AP Bill Number:</span>
            <span class="font-semibold text-ink-900">{{ invoice.ap_bill.bill_no }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-ink-500">AP Bill Status:</span>
            <StatusBadge :status="invoice.ap_bill.status" />
          </div>
          <div class="flex items-center justify-between">
            <span class="text-ink-500">AP Bill Amount:</span>
            <span class="font-bold text-ink-900">{{ formatCurrency(invoice.ap_bill.amount) }}</span>
          </div>
          <p class="text-xs text-ink-500 mt-2">
            Disbursement & payment scheduling is managed directly within the Accounting AP module.
          </p>
        </div>
        <div v-else class="text-sm text-ink-500 space-y-2">
          <p>
            No AP Bill created yet. Once approved, this invoice will be automatically handed off to Accounting's AP engine.
          </p>
        </div>
      </Panel>
    </div>

    <!-- Interactive Three-Way Match Grid (§3F) -->
    <Panel title="Authoritative Three-Way Match Engine (PO × GR × Invoice)" class="mt-6">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="bg-surface-elevated text-xs font-semibold text-ink-600 uppercase tracking-wider text-left">
              <th rowspan="2" class="py-2.5 px-3 border-r border-border align-bottom">Item Description</th>
              <th colspan="2" class="py-1.5 px-3 border-r border-border text-center bg-blue-50/50 text-blue-900">
                1. Purchase Order (PO)
              </th>
              <th class="py-1.5 px-3 border-r border-border text-center bg-emerald-50/50 text-emerald-900">
                2. Goods Receipt (GR)
              </th>
              <th colspan="2" class="py-1.5 px-3 border-r border-border text-center bg-purple-50/50 text-purple-900">
                3. Vendor Invoice
              </th>
              <th colspan="2" class="py-1.5 px-3 border-r border-border text-center">Variance</th>
              <th rowspan="2" class="py-2.5 px-3 text-center align-bottom">Match Result</th>
            </tr>
            <tr class="bg-surface-elevated text-xs font-semibold text-ink-500 text-right">
              <th class="py-1.5 px-2 w-20">Ordered</th>
              <th class="py-1.5 px-2 w-28 border-r border-border">PO Price</th>
              <th class="py-1.5 px-2 w-24 border-r border-border">Received</th>
              <th class="py-1.5 px-2 w-20">Billed</th>
              <th class="py-1.5 px-2 w-28 border-r border-border">Inv Price</th>
              <th class="py-1.5 px-2 w-20">Qty Δ</th>
              <th class="py-1.5 px-2 w-20 border-r border-border">Price Δ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="match in invoice.matches" :key="match.id" class="align-middle">
              <td class="py-3 px-3 font-medium text-ink-900 border-r border-border">
                {{ match.description }}
              </td>
              <!-- PO Leg -->
              <td class="py-3 px-2 text-right text-ink-700">
                {{ match.po_qty }}
              </td>
              <td class="py-3 px-2 text-right text-ink-700 border-r border-border">
                {{ formatCurrency(match.po_price) }}
              </td>
              <!-- GR Leg -->
              <td class="py-3 px-2 text-right font-medium text-emerald-700 border-r border-border">
                {{ match.gr_qty }}
              </td>
              <!-- Invoice Leg -->
              <td class="py-3 px-2 text-right font-bold text-purple-900">
                {{ match.invoice_qty }}
              </td>
              <td class="py-3 px-2 text-right font-semibold text-purple-900 border-r border-border">
                {{ formatCurrency(match.invoice_price) }}
              </td>
              <!-- Variance -->
              <td class="py-3 px-2 text-right text-xs" :class="match.qty_variance_pct > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">
                {{ match.qty_variance_pct }}%
              </td>
              <td class="py-3 px-2 text-right text-xs border-r border-border" :class="match.price_variance_pct > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'">
                {{ match.price_variance_pct }}%
              </td>
              <!-- Result -->
              <td class="py-3 px-3 text-center">
                <span
                  v-if="match.within_tolerance"
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800"
                >
                  ✓ Match
                </span>
                <span
                  v-else
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800"
                >
                  ✕ Mismatch
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>

    <!-- Reject Modal -->
    <Modal :show="showRejectModal" @close="showRejectModal = false">
      <div class="p-6">
        <h3 class="text-lg font-semibold text-ink-900">Reject Invoice</h3>
        <p class="mt-1 text-sm text-ink-600">Provide an optional rejection reason to return this invoice to procurement.</p>
        <div class="mt-4">
          <FormTextarea
            v-model="rejectForm.reason"
            name="reason"
            label="Rejection Reason"
            placeholder="e.g. Price variance not approved by purchasing manager"
            :rows="3"
          />
        </div>
        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showRejectModal = false">Cancel</SecondaryButton>
          <DangerButton :disabled="rejectForm.processing" @click="submitReject">
            Reject Invoice
          </DangerButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
