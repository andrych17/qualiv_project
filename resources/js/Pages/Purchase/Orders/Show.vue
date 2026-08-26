<!-- Purchase Order Show (§3D) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface LineItem {
  id: number
  line_no: number
  catalog_item: { id: number; item_code: string; description: string; unit: string } | null
  description: string
  qty_ordered: number
  qty_received: number
  unit_price: number
  tax_amount: number
  line_total: number
  expected_delivery_date: string | null
  category: { id: number; name: string; kind: string } | null
  local_content_pct: number | null
}

interface RevisionItem {
  id: number
  revision_no: number
  revised_by: string | null
  revised_at: string | null
  snapshot: Record<string, unknown>
}

interface ReceiptSummaryItem {
  id: number
  gr_no: string
  received_at: string | null
  receiver_name: string | null
  status: string
}

interface InvoiceSummaryItem {
  id: number
  supplier_invoice_no: string
  supplier_invoice_date: string | null
  amount: number
  match_status: string
  status: string
}

const props = defineProps<{
  order: {
    id: number
    uuid: string
    po_no: string
    supplier: { id: number; name: string } | null
    pr: { id: number; pr_no: string } | null
    creator: { id: number; name: string } | null
    ship_to: string | null
    bill_to: string | null
    currency_code: string
    incoterms: string | null
    payment_terms_days: number
    status: string
    revision_no: number
    subtotal: number
    tax_amount: number
    total_amount: number
    expected_delivery_date: string | null
    ack_status: string | null
    created_at: string | null
    lines: LineItem[]
    revisions: RevisionItem[]
    receipts: ReceiptSummaryItem[]
    invoices: InvoiceSummaryItem[]
  }
}>()

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: props.order.currency_code || 'IDR',
    maximumFractionDigits: 0,
  }).format(val)
}

// Acknowledgment Modal
const showAckModal = ref(false)
const ackForm = useForm({
  ack_status: 'accepted',
  notes: '',
})

const submitAck = () => {
  ackForm.post(route('purchase.orders.acknowledge', props.order.id), {
    onSuccess: () => {
      showAckModal.value = false
    },
  })
}

// Reject Modal
const showRejectModal = ref(false)
const rejectForm = useForm({
  reason: '',
})

const submitReject = () => {
  rejectForm.post(route('purchase.orders.reject', props.order.id), {
    onSuccess: () => {
      showRejectModal.value = false
    },
  })
}

// Revision view modal
const activeSnapshot = ref<Record<string, unknown> | null>(null)

const submitForApproval = () => {
  router.post(route('purchase.orders.submit', props.order.id))
}

const approve = () => {
  router.post(route('purchase.orders.approve', props.order.id))
}

const { confirm } = useConfirm()

const sendToSupplier = () => {
  router.post(route('purchase.orders.send', props.order.id))
}

const closeOrder = () => {
  confirm({
    title: 'Close Purchase Order?',
    description: 'Are you sure you want to close this purchase order?',
    confirmText: 'Close Order',
    onConfirm: () => router.post(route('purchase.orders.close', props.order.id)),
  })
}

const cancelOrder = () => {
  confirm({
    title: 'Cancel Purchase Order?',
    description: 'Are you sure you want to cancel this purchase order?',
    variant: 'destructive',
    confirmText: 'Cancel Order',
    onConfirm: () => router.post(route('purchase.orders.cancel', props.order.id)),
  })
}

const hasReceivedGoods = computed(() => {
  return props.order.lines.some((l) => l.qty_received > 0)
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${order.po_no} (Rev. ${order.revision_no})`" description="Purchase Order (§3D).">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('purchase.orders.index')">Back to list</SecondaryButton>

          <!-- Draft: Edit & Submit -->
          <Link
            v-if="order.status === 'draft'"
            :href="route('purchase.orders.edit', order.id)"
            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-ink-700 shadow-sm hover:bg-surface-elevated transition"
          >
            Edit
          </Link>
          <PrimaryButton
            v-if="order.status === 'draft'"
            @click="submitForApproval"
          >
            Submit for approval
          </PrimaryButton>

          <!-- Pending Approval: Approve & Reject -->
          <PrimaryButton
            v-if="order.status === 'pending_approval'"
            @click="approve"
          >
            Approve
          </PrimaryButton>
          <DangerButton
            v-if="order.status === 'pending_approval'"
            @click="showRejectModal = true"
          >
            Reject
          </DangerButton>

          <!-- Approved: Send to Supplier -->
          <PrimaryButton
            v-if="order.status === 'approved'"
            @click="sendToSupplier"
          >
            Send to Supplier
          </PrimaryButton>

          <!-- Sent: Record Acknowledgment -->
          <PrimaryButton
            v-if="order.status === 'sent'"
            @click="showAckModal = true"
          >
            Record Acknowledgment
          </PrimaryButton>

          <!-- Receive Goods action (§3E) -->
          <Link
            v-if="['approved', 'sent', 'acknowledged', 'partially_received'].includes(order.status)"
            :href="route('purchase.receipts.create', { po_id: order.id })"
            class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 transition"
          >
            Receive Goods (GR)
          </Link>

          <!-- Capture Invoice action (§3F) -->
          <Link
            v-if="['approved', 'sent', 'acknowledged', 'partially_received', 'received'].includes(order.status)"
            :href="route('purchase.invoices.create', { po_id: order.id })"
            class="inline-flex items-center rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-purple-700 transition"
          >
            Capture Invoice
          </Link>

          <!-- Amend for post-approval active states -->
          <Link
            v-if="['approved', 'sent', 'acknowledged', 'partially_received'].includes(order.status)"
            :href="route('purchase.orders.edit', order.id)"
            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-ink-700 shadow-sm hover:bg-surface-elevated transition"
          >
            Amend PO
          </Link>

          <!-- Close order action -->
          <SecondaryButton
            v-if="['sent', 'acknowledged', 'partially_received', 'received'].includes(order.status)"
            @click="closeOrder"
          >
            Close PO
          </SecondaryButton>

          <!-- Cancel order action (blocked if goods received) -->
          <SecondaryButton
            v-if="!['closed', 'cancelled'].includes(order.status) && !hasReceivedGoods"
            class="text-rose-600 hover:text-rose-700"
            @click="cancelOrder"
          >
            Cancel PO
          </SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <!-- Header Details -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
      <Panel title="Order Details" class="md:col-span-2">
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3 text-sm">
          <div>
            <dt class="text-ink-500 font-medium">Status</dt>
            <dd class="mt-1"><StatusBadge :status="order.status" /></dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Total Amount</dt>
            <dd class="mt-1 text-base font-bold text-ink-900">{{ formatCurrency(order.total_amount) }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Supplier / Vendor</dt>
            <dd class="mt-1 font-semibold text-ink-900">{{ order.supplier?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Linked PR</dt>
            <dd class="mt-1">
              <Link v-if="order.pr" :href="route('purchase.requisitions.show', order.pr.id)" class="text-accent hover:underline font-medium">
                {{ order.pr.pr_no }}
              </Link>
              <span v-else class="text-ink-500">Standalone PO</span>
            </dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Delivery Date</dt>
            <dd class="mt-1 text-ink-900">{{ order.expected_delivery_date ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Payment Terms</dt>
            <dd class="mt-1 text-ink-900">{{ order.payment_terms_days }} days</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Currency / Incoterms</dt>
            <dd class="mt-1 text-ink-900">{{ order.currency_code }} / {{ order.incoterms || '—' }}</dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Acknowledgment</dt>
            <dd class="mt-1">
              <span v-if="order.ack_status" class="font-medium capitalize text-ink-900">{{ order.ack_status.replace('_', ' ') }}</span>
              <span v-else class="text-ink-400">Pending</span>
            </dd>
          </div>
          <div>
            <dt class="text-ink-500 font-medium">Created At</dt>
            <dd class="mt-1 text-ink-900">{{ order.created_at ?? '—' }}</dd>
          </div>
        </dl>

        <div v-if="order.ship_to || order.bill_to" class="mt-4 pt-4 border-t border-border grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div v-if="order.ship_to">
            <h4 class="text-xs font-semibold text-ink-500 uppercase tracking-wider">Ship-To</h4>
            <p class="mt-1 text-ink-700">{{ order.ship_to }}</p>
          </div>
          <div v-if="order.bill_to">
            <h4 class="text-xs font-semibold text-ink-500 uppercase tracking-wider">Bill-To</h4>
            <p class="mt-1 text-ink-700">{{ order.bill_to }}</p>
          </div>
        </div>
      </Panel>

      <!-- Revision History Panel -->
      <div class="space-y-6">
        <Panel title="Goods Receipts (§3E)">
          <div v-if="order.receipts.length > 0" class="space-y-2">
            <div
              v-for="gr in order.receipts"
              :key="gr.id"
              class="p-2.5 border border-border rounded-md bg-surface-elevated flex items-center justify-between"
            >
              <div>
                <Link :href="route('purchase.receipts.show', gr.id)" class="text-sm font-semibold text-accent hover:underline">
                  {{ gr.gr_no }}
                </Link>
                <div class="text-xs text-ink-500">{{ gr.receiver_name ?? '—' }} • {{ gr.received_at }}</div>
              </div>
              <StatusBadge :status="gr.status" />
            </div>
          </div>
          <div v-else class="text-sm text-ink-500">
            No goods receipts recorded yet.
          </div>
        </Panel>

        <Panel title="Vendor Invoices (§3F)">
          <div v-if="order.invoices.length > 0" class="space-y-2">
            <div
              v-for="inv in order.invoices"
              :key="inv.id"
              class="p-2.5 border border-border rounded-md bg-surface-elevated flex items-center justify-between"
            >
              <div>
                <Link :href="route('purchase.invoices.show', inv.id)" class="text-sm font-semibold text-accent hover:underline">
                  {{ inv.supplier_invoice_no }}
                </Link>
                <div class="text-xs text-ink-500">{{ inv.supplier_invoice_date }} • {{ formatCurrency(inv.amount) }}</div>
              </div>
              <div class="text-right">
                <span
                  v-if="inv.match_status === 'matched'"
                  class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-800"
                >
                  3-Way Matched
                </span>
                <span
                  v-else-if="inv.match_status === 'mismatch'"
                  class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800"
                >
                  Mismatch
                </span>
              </div>
            </div>
          </div>
          <div v-else class="text-sm text-ink-500">
            No vendor invoices captured yet.
          </div>
        </Panel>

        <Panel title="Amendment History (§3D)">
          <div v-if="order.revisions.length > 0" class="space-y-3">
            <div
              v-for="rev in order.revisions"
              :key="rev.id"
              class="p-3 border border-border rounded-md bg-surface-elevated flex items-center justify-between"
            >
              <div>
                <div class="text-sm font-semibold text-ink-900">Revision v{{ rev.revision_no }}</div>
                <div class="text-xs text-ink-500">{{ rev.revised_by ?? 'System' }} • {{ rev.revised_at }}</div>
              </div>
              <button
                type="button"
                class="text-xs font-medium text-accent hover:underline"
                @click="activeSnapshot = rev.snapshot"
              >
                View Snapshot
              </button>
            </div>
          </div>
          <div v-else class="text-sm text-ink-500">
            Current version is v{{ order.revision_no }} (no prior amendments).
          </div>
        </Panel>
      </div>
    </div>

    <!-- Line Items Table -->
    <Panel title="Order Line Items" class="mt-6">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-sm">
          <thead>
            <tr class="text-left text-xs font-semibold text-ink-500 uppercase tracking-wider">
              <th class="py-2.5 px-3 w-12 text-center">#</th>
              <th class="py-2.5 px-3">Item / Description</th>
              <th class="py-2.5 px-3 w-28 text-right">Qty Ordered</th>
              <th class="py-2.5 px-3 w-28 text-right">Qty Received</th>
              <th class="py-2.5 px-3 w-36 text-right">Unit Price</th>
              <th class="py-2.5 px-3 w-28 text-right">Tax</th>
              <th class="py-2.5 px-3 w-24 text-right">TKDN %</th>
              <th class="py-2.5 px-3 w-40 text-right">Line Total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="line in order.lines" :key="line.id">
              <td class="py-3 px-3 text-center text-ink-500">{{ line.line_no }}</td>
              <td class="py-3 px-3">
                <div class="font-medium text-ink-900">{{ line.description }}</div>
                <div v-if="line.catalog_item" class="text-xs text-ink-500 font-mono">
                  Catalog: {{ line.catalog_item.item_code }} ({{ line.catalog_item.unit }})
                </div>
              </td>
              <td class="py-3 px-3 text-right font-medium text-ink-900">
                {{ line.qty_ordered }}
              </td>
              <td class="py-3 px-3 text-right">
                <span :class="line.qty_received > 0 ? 'font-semibold text-emerald-700' : 'text-ink-500'">
                  {{ line.qty_received }}
                </span>
              </td>
              <td class="py-3 px-3 text-right text-ink-800">
                {{ formatCurrency(line.unit_price) }}
              </td>
              <td class="py-3 px-3 text-right text-ink-600">
                {{ formatCurrency(line.tax_amount) }}
              </td>
              <td class="py-3 px-3 text-right text-ink-600">
                {{ line.local_content_pct !== null ? `${line.local_content_pct}%` : '—' }}
              </td>
              <td class="py-3 px-3 text-right font-semibold text-ink-900">
                {{ formatCurrency(line.line_total) }}
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-border font-medium text-ink-700">
              <td colspan="7" class="py-2 px-3 text-right">Subtotal:</td>
              <td class="py-2 px-3 text-right">{{ formatCurrency(order.subtotal) }}</td>
            </tr>
            <tr class="font-medium text-ink-700">
              <td colspan="7" class="py-2 px-3 text-right">Tax:</td>
              <td class="py-2 px-3 text-right">{{ formatCurrency(order.tax_amount) }}</td>
            </tr>
            <tr class="border-t-2 border-border font-bold text-ink-900">
              <td colspan="7" class="py-3 px-3 text-right">Total Amount:</td>
              <td class="py-3 px-3 text-right text-base text-accent">{{ formatCurrency(order.total_amount) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>

    <!-- Supplier Acknowledgment Modal -->
    <Modal :show="showAckModal" @close="showAckModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Record Supplier Acknowledgment</h3>
        <p class="mt-1 text-sm text-ink-600">Capture supplier response for {{ order.po_no }} (§3D).</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitAck">
          <FormSelect
            v-model="ackForm.ack_status"
            name="ack_status"
            label="Acknowledgment Status *"
            :options="[
              { label: 'Accepted (Fully Confirmed)', value: 'accepted' },
              { label: 'Accepted with Changes (Lead Time / Price / Terms)', value: 'accepted_with_changes' },
              { label: 'Rejected (Cannot Fulfill)', value: 'rejected' },
            ]"
            :error="ackForm.errors.ack_status"
            required
          />

          <FormTextarea
            v-model="ackForm.notes"
            name="notes"
            label="Notes / Supplier Comments"
            placeholder="Details provided by supplier…"
            :rows="3"
            :error="ackForm.errors.notes"
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showAckModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="ackForm.processing">Save Acknowledgment</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Reject Modal -->
    <Modal :show="showRejectModal" @close="showRejectModal = false">
      <div class="p-6">
        <h3 class="text-lg font-bold text-ink-900">Reject Purchase Order</h3>
        <p class="mt-1 text-sm text-ink-600">Provide a reason for rejecting this purchase order.</p>

        <form class="mt-4 space-y-4" @submit.prevent="submitReject">
          <FormTextarea
            v-model="rejectForm.reason"
            name="reason"
            label="Rejection Reason"
            placeholder="Reason for rejection…"
            :rows="3"
            :error="rejectForm.errors.reason"
            required
          />

          <div class="mt-6 flex justify-end gap-3">
            <SecondaryButton @click="showRejectModal = false">Cancel</SecondaryButton>
            <DangerButton type="submit" :disabled="rejectForm.processing">Confirm Rejection</DangerButton>
          </div>
        </form>
      </div>
    </Modal>

    <!-- Snapshot Viewer Modal -->
    <Modal :show="!!activeSnapshot" @close="activeSnapshot = null">
      <div class="p-6 max-w-2xl">
        <h3 class="text-lg font-bold text-ink-900">Revision Snapshot</h3>
        <p class="mt-1 text-sm text-ink-600">Historical state of the purchase order at that revision.</p>

        <div class="mt-4 max-h-96 overflow-y-auto bg-surface-elevated p-4 rounded-md border border-border">
          <pre class="text-xs font-mono text-ink-800 whitespace-pre-wrap">{{ JSON.stringify(activeSnapshot, null, 2) }}</pre>
        </div>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="activeSnapshot = null">Close</SecondaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
