<!-- Sales Order Detail & Fulfillment / Invoicing (§3F) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface SalesOrderLine {
  id: number
  line_no: number
  item_type: string
  description: string
  qty_ordered: number
  qty_delivered: number
  qty_invoiced: number
  unit_price: number
  discount_amount: number
  tax_amount: number
  line_total: number
}

interface DeliveryItem {
  id: number
  uuid: string
  status: string
  carrier: string | null
  tracking_number: string | null
  shipped_at: string | null
  delivered_at: string | null
  lines: Array<{ qty_shipped: number; sales_order_line?: { description: string } }>
}

interface InvoiceItem {
  id: number
  invoice_no: string
  status: string
  issue_date: string
  due_date: string
  total_amount: number
  balance_due: number
  payment_status: string
}

interface SalesOrderDetail {
  id: number
  uuid: string
  so_number: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  quote: { id: number; uuid: string; revision_no: number } | null
  price_list: { id: number; name: string } | null
  creator: { id: number; name: string } | null
  lines: SalesOrderLine[]
  deliveries: DeliveryItem[]
  returns: Array<{ id: number; uuid: string; status: string; reason_code: string }>
}

const props = defineProps<{
  order: SalesOrderDetail
  creditExposure: {
    credit_limit: number
    open_ar_balance: number
    available_credit: number
    on_hold: boolean
    payment_terms_days: number
  }
  invoices: InvoiceItem[]
  subtotal: number
  totalDiscount: number
  totalTax: number
  totalAmount: number
  qtyOrderedTotal: number
  qtyDeliveredTotal: number
  qtyInvoicedTotal: number
}>()

const activeTab = ref<'lines' | 'deliveries' | 'invoices' | 'returns'>('lines')

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const { confirm } = useConfirm()

const confirmOrder = () => {
  router.post(route('sales.orders.confirm', props.order.id))
}

const cancelOrder = () => {
  confirm({
    title: 'Cancel Sales Order?',
    description: 'Are you sure you want to cancel this order? This action cannot be undone.',
    variant: 'destructive',
    confirmText: 'Cancel Order',
    onConfirm: () => router.post(route('sales.orders.cancel', props.order.id)),
  })
}

const requestInvoice = () => {
  confirm({
    title: 'Submit Invoice Request?',
    description: 'Submit invoice request to Accounting module?',
    confirmText: 'Submit Invoice',
    onConfirm: () => router.post(route('sales.orders.invoice', props.order.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="`Sales Order ${props.order.so_number}`"
      :description="`Created on ${props.order.created_at.slice(0, 10)} by ${props.order.creator?.name ?? 'System'}`"
    >
      <template #actions>
        <SecondaryButton :href="route('sales.orders.index')">&larr; Back</SecondaryButton>

        <SecondaryButton
          v-if="props.order.status === 'draft'"
          :href="route('sales.orders.edit', props.order.id)"
        >
          Edit Order
        </SecondaryButton>

        <PrimaryButton
          v-if="props.order.status === 'draft'"
          @click="confirmOrder"
        >
          Confirm Order
        </PrimaryButton>

        <SecondaryButton
          v-if="['confirmed', 'partially_fulfilled'].includes(props.order.status)"
          :href="route('sales.deliveries.create', { so_hdr_id: props.order.id })"
        >
          Create Delivery
        </SecondaryButton>

        <SecondaryButton
          v-if="['confirmed', 'partially_fulfilled', 'fulfilled'].includes(props.order.status)"
          @click="requestInvoice"
        >
          Request Invoice
        </SecondaryButton>

        <SecondaryButton
          v-if="['confirmed', 'partially_fulfilled', 'fulfilled'].includes(props.order.status)"
          :href="route('sales.returns.create', { so_hdr_id: props.order.id })"
        >
          Create Return
        </SecondaryButton>

        <DangerButton
          v-if="props.order.status === 'draft'"
          @click="cancelOrder"
        >
          Cancel Order
        </DangerButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Order Overview & Credit Check Status -->
      <div class="lg:col-span-2 space-y-6">
        <Panel title="Order Details">
          <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <p class="text-xs text-ink-500 font-medium">Customer</p>
              <p class="mt-1 font-semibold text-ink-900">{{ props.order.customer?.name ?? 'Customer' }}</p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Order Status</p>
              <div class="mt-1"><StatusBadge :status="props.order.status" /></div>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Originating Quote</p>
              <p class="mt-1 text-ink-900">
                <Link v-if="props.order.quote" :href="route('sales.quotations.show', props.order.quote.id)" class="text-accent hover:underline">
                  Rev. {{ props.order.quote.revision_no }}
                </Link>
                <span v-else>Direct Entry</span>
              </p>
            </div>
            <div>
              <p class="text-xs text-ink-500 font-medium">Price List</p>
              <p class="mt-1 text-ink-900">{{ props.order.price_list?.name ?? 'Default' }}</p>
            </div>
          </div>
        </Panel>

        <!-- Navigation Tabs for Line Items, Deliveries, Invoices, Returns -->
        <div>
          <div class="flex items-center gap-2 border-b border-border pb-2">
            <button
              type="button"
              @click="activeTab = 'lines'"
              class="px-3 py-1.5 text-xs font-medium rounded-md transition"
              :class="activeTab === 'lines' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:bg-surface-100'"
            >
              Order Lines ({{ props.order.lines.length }})
            </button>
            <button
              type="button"
              @click="activeTab = 'deliveries'"
              class="px-3 py-1.5 text-xs font-medium rounded-md transition"
              :class="activeTab === 'deliveries' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:bg-surface-100'"
            >
              Deliveries ({{ props.order.deliveries.length }})
            </button>
            <button
              type="button"
              @click="activeTab = 'invoices'"
              class="px-3 py-1.5 text-xs font-medium rounded-md transition"
              :class="activeTab === 'invoices' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:bg-surface-100'"
            >
              Accounting Invoices ({{ props.invoices.length }})
            </button>
            <button
              type="button"
              @click="activeTab = 'returns'"
              class="px-3 py-1.5 text-xs font-medium rounded-md transition"
              :class="activeTab === 'returns' ? 'bg-ink-900 text-white font-semibold' : 'text-ink-600 hover:bg-surface-100'"
            >
              Returns ({{ props.order.returns.length }})
            </button>
          </div>

          <!-- Lines Tab -->
          <div v-if="activeTab === 'lines'" class="mt-4 rounded-lg border border-border bg-surface-0 p-4">
            <div class="overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                  <tr>
                    <th class="py-2.5 px-3">#</th>
                    <th class="py-2.5 px-3">Description</th>
                    <th class="py-2.5 px-3 text-right">Ordered</th>
                    <th class="py-2.5 px-3 text-right">Delivered</th>
                    <th class="py-2.5 px-3 text-right">Invoiced</th>
                    <th class="py-2.5 px-3 text-right">Unit Price</th>
                    <th class="py-2.5 px-3 text-right">Line Total</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border">
                  <tr v-for="line in props.order.lines" :key="line.id" class="hover:bg-surface-50">
                    <td class="py-2.5 px-3 font-mono text-xs text-ink-400">{{ line.line_no }}</td>
                    <td class="py-2.5 px-3 font-medium text-ink-900">{{ line.description }}</td>
                    <td class="py-2.5 px-3 text-right font-mono">{{ line.qty_ordered }}</td>
                    <td class="py-2.5 px-3 text-right font-mono" :class="Number(line.qty_delivered) >= Number(line.qty_ordered) ? 'text-emerald-600 font-semibold' : 'text-amber-600'">
                      {{ line.qty_delivered }}
                    </td>
                    <td class="py-2.5 px-3 text-right font-mono" :class="Number(line.qty_invoiced) >= Number(line.qty_ordered) ? 'text-emerald-600 font-semibold' : 'text-ink-600'">
                      {{ line.qty_invoiced }}
                    </td>
                    <td class="py-2.5 px-3 text-right font-mono">{{ formatCurrency(Number(line.unit_price)) }}</td>
                    <td class="py-2.5 px-3 text-right font-mono font-semibold text-ink-900">
                      {{ formatCurrency(Number(line.line_total) - Number(line.discount_amount) + Number(line.tax_amount)) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mt-4 flex justify-end border-t border-border pt-4">
              <div class="w-72 space-y-1.5 text-sm">
                <div class="flex justify-between text-ink-600">
                  <span>Subtotal:</span>
                  <span class="font-mono">{{ formatCurrency(props.subtotal) }}</span>
                </div>
                <div class="flex justify-between text-ink-600">
                  <span>Total Discount:</span>
                  <span class="font-mono text-emerald-600">- {{ formatCurrency(props.totalDiscount) }}</span>
                </div>
                <div class="flex justify-between text-ink-600">
                  <span>Total Tax:</span>
                  <span class="font-mono">+ {{ formatCurrency(props.totalTax) }}</span>
                </div>
                <div class="flex justify-between font-bold text-ink-900 pt-2 border-t border-border">
                  <span>Grand Total:</span>
                  <span class="font-mono text-lg text-accent">{{ formatCurrency(props.totalAmount) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Deliveries Tab -->
          <div v-if="activeTab === 'deliveries'" class="mt-4 rounded-lg border border-border bg-surface-0 p-4">
            <table v-if="props.order.deliveries.length > 0" class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3">Delivery UUID</th>
                  <th class="py-2 px-3">Carrier / Tracking</th>
                  <th class="py-2 px-3">Status</th>
                  <th class="py-2 px-3">Shipped At</th>
                  <th class="py-2 px-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="dlv in props.order.deliveries" :key="dlv.id" class="hover:bg-surface-50">
                  <td class="py-2.5 px-3 font-mono text-xs text-accent">
                    <Link :href="route('sales.deliveries.show', dlv.id)" class="hover:underline">
                      {{ dlv.uuid.slice(0, 8) }}…
                    </Link>
                  </td>
                  <td class="py-2.5 px-3 text-ink-700">
                    <span v-if="dlv.carrier">{{ dlv.carrier }} ({{ dlv.tracking_number ?? 'No tracking' }})</span>
                    <span v-else class="text-ink-400">Unassigned</span>
                  </td>
                  <td class="py-2.5 px-3"><StatusBadge :status="dlv.status" /></td>
                  <td class="py-2.5 px-3 text-ink-600">{{ dlv.shipped_at ?? '-' }}</td>
                  <td class="py-2.5 px-3 text-right">
                    <Link :href="route('sales.deliveries.show', dlv.id)" class="text-xs font-semibold text-accent hover:underline">
                      View Delivery &rarr;
                    </Link>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-else class="py-6 text-center text-sm text-ink-500">No deliveries created for this order yet.</p>
          </div>

          <!-- Accounting Invoices Tab -->
          <div v-if="activeTab === 'invoices'" class="mt-4 rounded-lg border border-border bg-surface-0 p-4">
            <table v-if="props.invoices.length > 0" class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3">Invoice #</th>
                  <th class="py-2 px-3">Issue Date</th>
                  <th class="py-2 px-3">Due Date</th>
                  <th class="py-2 px-3">Total Amount</th>
                  <th class="py-2 px-3">Balance Due</th>
                  <th class="py-2 px-3">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="inv in props.invoices" :key="inv.id" class="hover:bg-surface-50">
                  <td class="py-2.5 px-3 font-medium text-accent">{{ inv.invoice_no }}</td>
                  <td class="py-2.5 px-3 text-ink-600">{{ inv.issue_date }}</td>
                  <td class="py-2.5 px-3 text-ink-600">{{ inv.due_date }}</td>
                  <td class="py-2.5 px-3 font-mono font-semibold">{{ formatCurrency(Number(inv.total_amount)) }}</td>
                  <td class="py-2.5 px-3 font-mono" :class="Number(inv.balance_due) > 0 ? 'text-rose-600 font-semibold' : 'text-emerald-600'">
                    {{ formatCurrency(Number(inv.balance_due)) }}
                  </td>
                  <td class="py-2.5 px-3"><StatusBadge :status="inv.status" /></td>
                </tr>
              </tbody>
            </table>
            <p v-else class="py-6 text-center text-sm text-ink-500">No invoices issued for this order yet.</p>
          </div>

          <!-- Returns Tab -->
          <div v-if="activeTab === 'returns'" class="mt-4 rounded-lg border border-border bg-surface-0 p-4">
            <table v-if="props.order.returns.length > 0" class="w-full text-left text-sm">
              <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
                <tr>
                  <th class="py-2 px-3">Return UUID</th>
                  <th class="py-2 px-3">Reason Code</th>
                  <th class="py-2 px-3">Status</th>
                  <th class="py-2 px-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="ret in props.order.returns" :key="ret.id" class="hover:bg-surface-50">
                  <td class="py-2.5 px-3 font-mono text-xs text-accent">
                    <Link :href="route('sales.returns.show', ret.id)" class="hover:underline">
                      {{ ret.uuid.slice(0, 8) }}…
                    </Link>
                  </td>
                  <td class="py-2.5 px-3 text-ink-900">{{ ret.reason_code }}</td>
                  <td class="py-2.5 px-3"><StatusBadge :status="ret.status" /></td>
                  <td class="py-2.5 px-3 text-right">
                    <Link :href="route('sales.returns.show', ret.id)" class="text-xs font-semibold text-accent hover:underline">
                      View Return &rarr;
                    </Link>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-else class="py-6 text-center text-sm text-ink-500">No returns filed against this order.</p>
          </div>
        </div>
      </div>

      <!-- Right Column: Customer Credit Profile & Exposure (§3K) -->
      <div class="space-y-6">
        <Panel title="Customer Credit Profile">
          <div class="space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-ink-600">Credit Limit:</span>
              <span class="font-mono font-semibold">{{ formatCurrency(props.creditExposure.credit_limit) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Open AR Balance:</span>
              <span class="font-mono text-rose-600">{{ formatCurrency(props.creditExposure.open_ar_balance) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Available Credit:</span>
              <span class="font-mono text-emerald-600 font-semibold">{{ formatCurrency(props.creditExposure.available_credit) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-ink-600">Payment Terms:</span>
              <span class="font-medium">{{ props.creditExposure.payment_terms_days }} Days</span>
            </div>
            <div class="flex justify-between pt-2 border-t border-border">
              <span class="text-ink-600">Account Standing:</span>
              <span v-if="props.creditExposure.on_hold" class="font-semibold text-rose-600">ON HOLD</span>
              <span v-else class="font-semibold text-emerald-600">Good Standing</span>
            </div>
          </div>
        </Panel>

        <Panel title="Fulfillment Summary">
          <div class="space-y-2 text-sm">
            <div class="flex justify-between text-ink-600">
              <span>Total Units Ordered:</span>
              <span class="font-mono font-medium">{{ props.qtyOrderedTotal }}</span>
            </div>
            <div class="flex justify-between text-ink-600">
              <span>Total Units Delivered:</span>
              <span class="font-mono font-medium">{{ props.qtyDeliveredTotal }}</span>
            </div>
            <div class="flex justify-between text-ink-600">
              <span>Total Units Invoiced:</span>
              <span class="font-mono font-medium">{{ props.qtyInvoicedTotal }}</span>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
