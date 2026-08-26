<!-- Customer Self-Service Portal (§3D) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface Partner {
  id: number
  name: string
  email: string | null
  phone: string | null
}

interface QuotationItem {
  id: number
  uuid: string
  revision_no: number
  status: string
  validity_date: string | null
  subtotal: number
  total_discount: number
  total_tax: number
  total_amount: number
  lines: Array<{ description: string; quantity: number; unit_price: number; line_total: number }>
}

interface OrderItem {
  id: number
  so_number: string
  status: string
  created_at: string
  subtotal: number
  total_discount: number
  total_tax: number
  total_amount: number
  lines: Array<{ description: string; qty_ordered: number; qty_delivered: number; qty_invoiced: number; unit_price: number }>
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
}

const props = defineProps<{
  token: string
  customer: Partner
  quotes: QuotationItem[]
  orders: OrderItem[]
  deliveries: DeliveryItem[]
  invoices: InvoiceItem[]
}>()

const activeTab = ref<'orders' | 'quotes' | 'deliveries' | 'invoices'>('orders')

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}
</script>

<template>
  <Head :title="`Customer Portal - ${props.customer.name}`" />

  <div class="min-h-screen bg-surface-50 text-ink-900">
    <!-- Header -->
    <header class="border-b border-border bg-surface-0 px-6 py-4 shadow-xs">
      <div class="mx-auto flex max-w-7xl items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-ink-900 text-white font-bold font-serif text-lg">
            N
          </div>
          <div>
            <h1 class="text-lg font-bold text-ink-900">Customer Portal</h1>
            <p class="text-xs text-ink-500">Welcome, {{ props.customer.name }}</p>
          </div>
        </div>

        <div class="text-right text-xs text-ink-500">
          <p class="font-medium text-ink-700">Self-Service Account Center</p>
          <p>Read-only verified access</p>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="mx-auto max-w-7xl px-6 py-8">
      <!-- Tabs -->
      <div class="flex items-center gap-2 border-b border-border pb-3">
        <button
          type="button"
          @click="activeTab = 'orders'"
          class="px-4 py-2 text-sm font-semibold rounded-md transition"
          :class="activeTab === 'orders' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-200'"
        >
          My Orders ({{ props.orders.length }})
        </button>
        <button
          type="button"
          @click="activeTab = 'quotes'"
          class="px-4 py-2 text-sm font-semibold rounded-md transition"
          :class="activeTab === 'quotes' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-200'"
        >
          Quotations ({{ props.quotes.length }})
        </button>
        <button
          type="button"
          @click="activeTab = 'deliveries'"
          class="px-4 py-2 text-sm font-semibold rounded-md transition"
          :class="activeTab === 'deliveries' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-200'"
        >
          Shipments & Deliveries ({{ props.deliveries.length }})
        </button>
        <button
          type="button"
          @click="activeTab = 'invoices'"
          class="px-4 py-2 text-sm font-semibold rounded-md transition"
          :class="activeTab === 'invoices' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-200'"
        >
          Invoices & Statements ({{ props.invoices.length }})
        </button>
      </div>

      <!-- Orders Panel -->
      <div v-if="activeTab === 'orders'" class="mt-6 space-y-4">
        <div v-for="order in props.orders" :key="order.id" class="border border-border rounded-lg bg-surface-0 p-5 shadow-xs">
          <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-3">
            <div>
              <span class="text-sm font-bold text-accent">{{ order.so_number }}</span>
              <span class="text-xs text-ink-500 ml-2">Ordered on {{ order.created_at.slice(0, 10) }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="font-mono text-base font-bold text-ink-900">{{ formatCurrency(Number(order.total_amount)) }}</span>
              <StatusBadge :status="order.status" />
            </div>
          </div>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead class="text-ink-500">
                <tr>
                  <th class="py-1">Item Description</th>
                  <th class="py-1 text-right">Qty Ordered</th>
                  <th class="py-1 text-right">Qty Delivered</th>
                  <th class="py-1 text-right">Unit Price</th>
                  <th class="py-1 text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(l, i) in order.lines" :key="i">
                  <td class="py-2 font-medium text-ink-900">{{ l.description }}</td>
                  <td class="py-2 text-right font-mono">{{ l.qty_ordered }}</td>
                  <td class="py-2 text-right font-mono text-emerald-600">{{ l.qty_delivered }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(Number(l.unit_price)) }}</td>
                  <td class="py-2 text-right font-mono font-semibold">{{ formatCurrency(Number(l.qty_ordered * l.unit_price)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-if="props.orders.length === 0" class="py-12 text-center text-sm text-ink-500">No active sales orders found.</p>
      </div>

      <!-- Quotations Panel -->
      <div v-if="activeTab === 'quotes'" class="mt-6 space-y-4">
        <div v-for="quote in props.quotes" :key="quote.id" class="border border-border rounded-lg bg-surface-0 p-5 shadow-xs">
          <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-3">
            <div>
              <span class="text-sm font-bold text-ink-900">Quotation (Rev. {{ quote.revision_no }})</span>
              <span class="text-xs text-ink-500 ml-2">Valid until {{ quote.validity_date ?? 'N/A' }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="font-mono text-base font-bold text-ink-900">{{ formatCurrency(Number(quote.total_amount)) }}</span>
              <StatusBadge :status="quote.status" />
            </div>
          </div>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead class="text-ink-500">
                <tr>
                  <th class="py-1">Description</th>
                  <th class="py-1 text-right">Quantity</th>
                  <th class="py-1 text-right">Unit Price</th>
                  <th class="py-1 text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="(l, i) in quote.lines" :key="i">
                  <td class="py-2 font-medium text-ink-900">{{ l.description }}</td>
                  <td class="py-2 text-right font-mono">{{ l.quantity }}</td>
                  <td class="py-2 text-right font-mono">{{ formatCurrency(Number(l.unit_price)) }}</td>
                  <td class="py-2 text-right font-mono font-semibold">{{ formatCurrency(Number(l.line_total)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-if="props.quotes.length === 0" class="py-12 text-center text-sm text-ink-500">No quotations found.</p>
      </div>

      <!-- Deliveries Panel -->
      <div v-if="activeTab === 'deliveries'" class="mt-6 space-y-4">
        <div v-for="dlv in props.deliveries" :key="dlv.id" class="border border-border rounded-lg bg-surface-0 p-5 shadow-xs">
          <div class="flex items-center justify-between border-b border-border pb-3">
            <div>
              <p class="text-sm font-semibold text-ink-900">
                Carrier: {{ dlv.carrier ?? 'Standard Courier' }}
                <span v-if="dlv.tracking_number" class="text-xs font-mono text-accent ml-2">(Tracking: {{ dlv.tracking_number }})</span>
              </p>
              <p class="text-xs text-ink-500">Shipped: {{ dlv.shipped_at ?? 'Preparing' }}</p>
            </div>
            <StatusBadge :status="dlv.status" />
          </div>
          <div class="mt-3">
            <ul class="text-xs space-y-1 text-ink-700">
              <li v-for="(l, i) in dlv.lines" :key="i">
                &bull; {{ l.sales_order_line?.description ?? 'Item' }} — <span class="font-mono font-bold">{{ l.qty_shipped }} units</span>
              </li>
            </ul>
          </div>
        </div>
        <p v-if="props.deliveries.length === 0" class="py-12 text-center text-sm text-ink-500">No shipments recorded.</p>
      </div>

      <!-- Invoices Panel -->
      <div v-if="activeTab === 'invoices'" class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
            <tr>
              <th class="py-3 px-4">Invoice #</th>
              <th class="py-3 px-4">Issue Date</th>
              <th class="py-3 px-4">Due Date</th>
              <th class="py-3 px-4 text-right">Invoice Total</th>
              <th class="py-3 px-4 text-right">Balance Due</th>
              <th class="py-3 px-4">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="inv in props.invoices" :key="inv.id" class="hover:bg-surface-50">
              <td class="py-3 px-4 font-medium text-accent">{{ inv.invoice_no }}</td>
              <td class="py-3 px-4 text-ink-600">{{ inv.issue_date }}</td>
              <td class="py-3 px-4 text-ink-600">{{ inv.due_date }}</td>
              <td class="py-3 px-4 text-right font-mono font-semibold">{{ formatCurrency(Number(inv.total_amount)) }}</td>
              <td class="py-3 px-4 text-right font-mono font-bold" :class="Number(inv.balance_due) > 0 ? 'text-rose-600' : 'text-emerald-600'">
                {{ formatCurrency(Number(inv.balance_due)) }}
              </td>
              <td class="py-3 px-4"><StatusBadge :status="inv.status" /></td>
            </tr>
            <tr v-if="props.invoices.length === 0">
              <td colspan="6" class="py-8 text-center text-ink-500">No invoices issued.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</template>
