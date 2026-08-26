<!-- Sales Orders List (§3F) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface OrderItem {
  id: number
  uuid: string
  so_number: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  quote: { id: number; uuid: string; revision_no: number } | null
  lines: Array<{ line_total: number; discount_amount: number; tax_amount: number; qty_ordered: number; qty_delivered: number; qty_invoiced: number }>
}

const props = defineProps<{
  orders: {
    data: OrderItem[]
    links: Array<{ url: string | null; label: string; active: boolean }>
  }
  statuses: string[]
  filters: { search?: string; status?: string; customer_id?: string }
  customers: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

const formatCurrency = (lines: Array<{ line_total: number; discount_amount: number; tax_amount: number }>, curr = 'IDR') => {
  const subtotal = lines.reduce((acc, l) => acc + Number(l.line_total), 0)
  const discount = lines.reduce((acc, l) => acc + Number(l.discount_amount), 0)
  const tax = lines.reduce((acc, l) => acc + Number(l.tax_amount), 0)
  const total = subtotal - discount + tax
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(total)
}

const applyFilters = () => {
  router.get(route('sales.orders.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Orders"
      description="Manage order fulfillment, delivery handoff, and billing request orchestration (§3F)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.orders.create')">New Sales Order</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="orders" />
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          @keydown.enter="applyFilters"
          type="text"
          placeholder="Search by SO number or customer…"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        />
        <select
          v-model="status"
          @change="applyFilters"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm text-ink-900 focus:border-accent focus:outline-none"
        >
          <option value="">All Statuses</option>
          <option v-for="st in props.statuses" :key="st" :value="st">{{ st.toUpperCase() }}</option>
        </select>
      </div>
    </div>

    <!-- Sales Orders Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">SO Number</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Source Quote</th>
            <th class="py-3 px-4">Total Amount</th>
            <th class="py-3 px-4">Fulfillment</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="order in props.orders.data" :key="order.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-semibold text-accent">
              <Link :href="route('sales.orders.show', order.id)" class="hover:underline">
                {{ order.so_number }}
              </Link>
            </td>
            <td class="py-3 px-4 font-medium text-ink-900">{{ order.customer?.name ?? '-' }}</td>
            <td class="py-3 px-4 text-xs text-ink-600">
              <span v-if="order.quote">Quote Rev. {{ order.quote.revision_no }}</span>
              <span v-else>Direct Order</span>
            </td>
            <td class="py-3 px-4 font-mono font-semibold text-ink-900">{{ formatCurrency(order.lines) }}</td>
            <td class="py-3 px-4 text-xs">
              <span class="font-mono text-ink-700">
                Delivered: {{ order.lines.reduce((s, l) => s + Number(l.qty_delivered), 0) }} /
                {{ order.lines.reduce((s, l) => s + Number(l.qty_ordered), 0) }}
              </span>
            </td>
            <td class="py-3 px-4"><StatusBadge :status="order.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.orders.show', order.id)" class="text-xs font-semibold text-accent hover:underline">
                View &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.orders.data.length === 0">
            <td colspan="7" class="py-8 text-center text-ink-500">No sales orders found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
