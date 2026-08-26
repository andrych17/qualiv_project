<!-- Sales Dashboard (§3A / §3N) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface DashboardProps {
  summary: {
    open_quotes_count: number
    open_quotes_value: number
    open_orders_count: number
    open_orders_value: number
    revenue_mtd: number
    overdue_invoices_count: number
    overdue_invoices_value: number
    open_returns_count: number
    customers_over_limit: number
  }
  funnel: {
    opportunities: { count: number; value: number }
    quotations: { count: number; value: number }
    orders: { count: number; value: number }
  }
  myOpportunities: Array<{
    id: number
    name: string
    stage: string
    estimated_value: number | null
    customer: { id: number; name: string } | null
    created_at: string
  }>
  myQuotes: Array<{
    id: number
    uuid: string
    revision_no: number
    status: string
    total_amount: number
    customer: { id: number; name: string } | null
    created_at: string
  }>
  myOrders: Array<{
    id: number
    so_number: string
    status: string
    total_amount: number
    customer: { id: number; name: string } | null
    created_at: string
  }>
  overdueInvoices: Array<{
    id: number
    invoice_no: string
    customer_name: string
    issue_date: string
    due_date: string
    balance_due: number
  }>
}

const props = defineProps<DashboardProps>()

const activeQueueTab = ref<'opportunities' | 'quotes' | 'orders' | 'overdue'>('opportunities')

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Management"
      description="Quote-to-cash pipeline, sales orders, deliveries, recurring contracts, and commission tracking."
    >
      <template #actions>
        <SecondaryButton :href="route('sales.quotations.create')">New Quotation</SecondaryButton>
        <PrimaryButton :href="route('sales.orders.create')">New Order</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="dashboard" />
    </div>

    <!-- Summary KPI Cards -->
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard
        title="Open Quotes"
        :value="formatCurrency(props.summary.open_quotes_value)"
        :description="`${props.summary.open_quotes_count} pending quotations`"
        icon="FileText"
        :href="route('sales.quotations.index')"
      />
      <StatCard
        title="Open Sales Orders"
        :value="formatCurrency(props.summary.open_orders_value)"
        :description="`${props.summary.open_orders_count} orders in progress`"
        icon="ShoppingCart"
        :href="route('sales.orders.index')"
      />
      <StatCard
        title="Revenue MTD"
        :value="formatCurrency(props.summary.revenue_mtd)"
        description="Month-to-date sales"
        icon="TrendingUp"
      />
      <StatCard
        title="Overdue Invoices"
        :value="formatCurrency(props.summary.overdue_invoices_value)"
        :description="`${props.summary.overdue_invoices_count} overdue receivables`"
        icon="AlertTriangle"
      />
    </div>

    <!-- Alert row if returns or credit issues exist -->
    <div v-if="props.summary.customers_over_limit > 0 || props.summary.open_returns_count > 0" class="mt-4 flex flex-wrap gap-4">
      <div v-if="props.summary.customers_over_limit > 0" class="flex items-center gap-2 rounded-md bg-amber-50 border border-amber-200 px-4 py-2 text-sm text-amber-800">
        <span class="font-semibold">{{ props.summary.customers_over_limit }}</span> customer(s) currently on credit hold / exceeded limit.
      </div>
      <div v-if="props.summary.open_returns_count > 0" class="flex items-center gap-2 rounded-md bg-sky-50 border border-sky-200 px-4 py-2 text-sm text-sky-800">
        <span class="font-semibold">{{ props.summary.open_returns_count }}</span> open return RMA request(s) awaiting processing.
      </div>
    </div>

    <!-- Funnel Analytics & Pipeline -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel title="Pipeline Funnel" class="lg:col-span-1">
        <div class="space-y-4">
          <div>
            <div class="flex justify-between text-sm font-medium">
              <span class="text-ink-600">1. Opportunities</span>
              <span class="font-semibold text-ink-900">{{ props.funnel.opportunities.count }} ({{ formatCurrency(props.funnel.opportunities.value) }})</span>
            </div>
            <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-surface-100">
              <div class="h-full bg-indigo-500 rounded-full" style="width: 100%"></div>
            </div>
          </div>

          <div>
            <div class="flex justify-between text-sm font-medium">
              <span class="text-ink-600">2. Quotations</span>
              <span class="font-semibold text-ink-900">{{ props.funnel.quotations.count }} ({{ formatCurrency(props.funnel.quotations.value) }})</span>
            </div>
            <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-surface-100">
              <div
                class="h-full bg-sky-500 rounded-full"
                :style="{ width: props.funnel.opportunities.count > 0 ? Math.min(100, Math.round((props.funnel.quotations.count / props.funnel.opportunities.count) * 100)) + '%' : '50%' }"
              ></div>
            </div>
          </div>

          <div>
            <div class="flex justify-between text-sm font-medium">
              <span class="text-ink-600">3. Confirmed Orders</span>
              <span class="font-semibold text-ink-900">{{ props.funnel.orders.count }} ({{ formatCurrency(props.funnel.orders.value) }})</span>
            </div>
            <div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-surface-100">
              <div
                class="h-full bg-emerald-500 rounded-full"
                :style="{ width: props.funnel.quotations.count > 0 ? Math.min(100, Math.round((props.funnel.orders.count / props.funnel.quotations.count) * 100)) + '%' : '30%' }"
              ></div>
            </div>
          </div>
        </div>

        <div class="mt-6 pt-4 border-t border-border flex items-center justify-between text-xs text-ink-500">
          <span>Quote-to-Cash loop active</span>
          <Link :href="route('sales.opportunities.index')" class="text-accent hover:underline font-medium">View pipeline &rarr;</Link>
        </div>
      </Panel>

      <!-- Work Queue Tabs -->
      <Panel title="My Work Queues" class="lg:col-span-2">
        <div class="flex items-center gap-2 border-b border-border pb-3">
          <button
            type="button"
            @click="activeQueueTab = 'opportunities'"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition"
            :class="activeQueueTab === 'opportunities' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-100'"
          >
            My Opportunities ({{ props.myOpportunities.length }})
          </button>
          <button
            type="button"
            @click="activeQueueTab = 'quotes'"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition"
            :class="activeQueueTab === 'quotes' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-100'"
          >
            My Quotes ({{ props.myQuotes.length }})
          </button>
          <button
            type="button"
            @click="activeQueueTab = 'orders'"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition"
            :class="activeQueueTab === 'orders' ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-surface-100'"
          >
            My Orders ({{ props.myOrders.length }})
          </button>
          <button
            type="button"
            @click="activeQueueTab = 'overdue'"
            class="px-3 py-1.5 text-xs font-medium rounded-md transition"
            :class="activeQueueTab === 'overdue' ? 'bg-rose-700 text-white' : 'text-ink-600 hover:bg-surface-100'"
          >
            Overdue Invoices ({{ props.overdueInvoices.length }})
          </button>
        </div>

        <!-- Opportunities Tab -->
        <div v-if="activeQueueTab === 'opportunities'" class="mt-4 overflow-x-auto">
          <table v-if="props.myOpportunities.length > 0" class="w-full text-left text-sm">
            <thead class="text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2">Opportunity</th>
                <th class="py-2">Customer</th>
                <th class="py-2">Est. Value</th>
                <th class="py-2">Stage</th>
                <th class="py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="opp in props.myOpportunities" :key="opp.id" class="hover:bg-surface-50">
                <td class="py-2.5 font-medium text-ink-900">{{ opp.name }}</td>
                <td class="py-2.5 text-ink-600">{{ opp.customer?.name ?? 'Prospect' }}</td>
                <td class="py-2.5 font-mono text-ink-700">{{ opp.estimated_value ? formatCurrency(opp.estimated_value) : '-' }}</td>
                <td class="py-2.5"><StatusBadge :status="opp.stage" /></td>
                <td class="py-2.5 text-right">
                  <Link :href="route('sales.opportunities.edit', opp.id)" class="text-xs font-medium text-accent hover:underline">Edit</Link>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="py-6 text-center text-sm text-ink-500">No active opportunities in your queue.</p>
        </div>

        <!-- Quotes Tab -->
        <div v-if="activeQueueTab === 'quotes'" class="mt-4 overflow-x-auto">
          <table v-if="props.myQuotes.length > 0" class="w-full text-left text-sm">
            <thead class="text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2">Customer</th>
                <th class="py-2">Revision</th>
                <th class="py-2">Total Amount</th>
                <th class="py-2">Status</th>
                <th class="py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="quote in props.myQuotes" :key="quote.id" class="hover:bg-surface-50">
                <td class="py-2.5 font-medium text-ink-900">{{ quote.customer?.name ?? 'Customer' }}</td>
                <td class="py-2.5 text-ink-600">Rev. {{ quote.revision_no }}</td>
                <td class="py-2.5 font-mono text-ink-900 font-semibold">{{ formatCurrency(quote.total_amount) }}</td>
                <td class="py-2.5"><StatusBadge :status="quote.status" /></td>
                <td class="py-2.5 text-right">
                  <Link :href="route('sales.quotations.show', quote.id)" class="text-xs font-medium text-accent hover:underline">View &rarr;</Link>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="py-6 text-center text-sm text-ink-500">No active quotations in your queue.</p>
        </div>

        <!-- Orders Tab -->
        <div v-if="activeQueueTab === 'orders'" class="mt-4 overflow-x-auto">
          <table v-if="props.myOrders.length > 0" class="w-full text-left text-sm">
            <thead class="text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2">Order #</th>
                <th class="py-2">Customer</th>
                <th class="py-2">Total Amount</th>
                <th class="py-2">Status</th>
                <th class="py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="order in props.myOrders" :key="order.id" class="hover:bg-surface-50">
                <td class="py-2.5 font-medium text-accent">{{ order.so_number }}</td>
                <td class="py-2.5 text-ink-700">{{ order.customer?.name ?? 'Customer' }}</td>
                <td class="py-2.5 font-mono text-ink-900 font-semibold">{{ formatCurrency(order.total_amount) }}</td>
                <td class="py-2.5"><StatusBadge :status="order.status" /></td>
                <td class="py-2.5 text-right">
                  <Link :href="route('sales.orders.show', order.id)" class="text-xs font-medium text-accent hover:underline">View &rarr;</Link>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="py-6 text-center text-sm text-ink-500">No active orders in your queue.</p>
        </div>

        <!-- Overdue Invoices Tab -->
        <div v-if="activeQueueTab === 'overdue'" class="mt-4 overflow-x-auto">
          <table v-if="props.overdueInvoices.length > 0" class="w-full text-left text-sm">
            <thead class="text-xs text-ink-500 uppercase border-b border-border">
              <tr>
                <th class="py-2">Invoice #</th>
                <th class="py-2">Customer</th>
                <th class="py-2">Due Date</th>
                <th class="py-2">Overdue Balance</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="inv in props.overdueInvoices" :key="inv.id" class="hover:bg-surface-50">
                <td class="py-2.5 font-medium text-rose-700">{{ inv.invoice_no }}</td>
                <td class="py-2.5 text-ink-900">{{ inv.customer_name }}</td>
                <td class="py-2.5 text-ink-600">{{ inv.due_date }}</td>
                <td class="py-2.5 font-mono text-rose-700 font-semibold">{{ formatCurrency(inv.balance_due) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else class="py-6 text-center text-sm text-emerald-600">Great! No overdue invoices found.</p>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
