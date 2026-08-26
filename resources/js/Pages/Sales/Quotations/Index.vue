<!-- Quotations List (§3E) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'

interface QuotationItem {
  id: number
  uuid: string
  revision_no: number
  validity_date: string | null
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  opportunity: { id: number; name: string } | null
  creator: { id: number; name: string } | null
  lines: Array<{ line_total: number; discount_amount: number; tax_amount: number }>
}

const props = defineProps<{
  quotations: {
    data: QuotationItem[]
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
  router.get(route('sales.quotations.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Quotations"
      description="Create, revise, and convert estimates into confirmed sales orders (§3E)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.quotations.create')">New Quotation</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="quotations" />
    </div>

    <!-- Filters -->
    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <input
          v-model="search"
          @keydown.enter="applyFilters"
          type="text"
          placeholder="Search by customer name or UUID…"
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

    <!-- Quotations Table -->
    <div class="mt-6 rounded-lg border border-border bg-surface-0 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="bg-surface-50 text-xs text-ink-500 uppercase border-b border-border">
          <tr>
            <th class="py-3 px-4">Quotation</th>
            <th class="py-3 px-4">Customer</th>
            <th class="py-3 px-4">Opportunity</th>
            <th class="py-3 px-4">Revision</th>
            <th class="py-3 px-4">Validity Date</th>
            <th class="py-3 px-4">Total Amount</th>
            <th class="py-3 px-4">Status</th>
            <th class="py-3 px-4 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="quote in props.quotations.data" :key="quote.id" class="hover:bg-surface-50">
            <td class="py-3 px-4 font-mono font-medium text-accent">
              <Link :href="route('sales.quotations.show', quote.id)" class="hover:underline">
                {{ quote.uuid.slice(0, 8) }}…
              </Link>
            </td>
            <td class="py-3 px-4 font-medium text-ink-900">{{ quote.customer?.name ?? '-' }}</td>
            <td class="py-3 px-4 text-ink-600">{{ quote.opportunity?.name ?? '-' }}</td>
            <td class="py-3 px-4 font-medium text-ink-700">Rev. {{ quote.revision_no }}</td>
            <td class="py-3 px-4 text-ink-600">{{ quote.validity_date ?? 'No expiry' }}</td>
            <td class="py-3 px-4 font-mono font-semibold text-ink-900">{{ formatCurrency(quote.lines) }}</td>
            <td class="py-3 px-4"><StatusBadge :status="quote.status" /></td>
            <td class="py-3 px-4 text-right">
              <Link :href="route('sales.quotations.show', quote.id)" class="text-xs font-semibold text-accent hover:underline">
                View &rarr;
              </Link>
            </td>
          </tr>
          <tr v-if="props.quotations.data.length === 0">
            <td colspan="8" class="py-8 text-center text-ink-500">No quotations found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
