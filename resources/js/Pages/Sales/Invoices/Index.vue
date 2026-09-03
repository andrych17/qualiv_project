<!-- Sales Invoices (§3I) — read-only view over Accounting's ar_invoices, scoped to invoices
     Sales itself requested. Detail lives on the owning Sales Order's page, not here. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Receipt } from 'lucide-vue-next'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface InvoiceItem {
  id: number
  invoice_no: string
  invoice_type: string
  status: string
  issue_date: string | null
  due_date: string | null
  total_amount: number
  open_balance: number
  currency_code: string
  customer: { id: number; name: string } | null
  subject_type: string | null
  subject_id: number | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  invoices: PaginatedData<InvoiceItem>
  statuses: string[]
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  accountingInstalled: boolean
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.invoices.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.replace(/_/g, ' ').toUpperCase(), value: st })),
  },
]

const columns = [
  { key: 'invoice_no', label: 'Invoice #', sortable: true },
  { key: 'customer', label: 'Customer' },
  { key: 'source', label: 'Source' },
  { key: 'issue_date', label: 'Issue Date', sortable: true },
  { key: 'due_date', label: 'Due Date', sortable: true },
  { key: 'total_amount', label: 'Total', sortable: true, align: 'right' as const },
  { key: 'open_balance', label: 'Open Balance', align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.invoices.index'), {
    search: search.value || undefined,
    status: filters.value.status || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Invoices"
      description="Customer invoices Accounting has posted against Sales orders and recurring contracts (§3I). Accounting owns the ledger — this is a read-only view."
    />

    <div class="mt-4">
      <SalesSubNav active="invoices" />
    </div>

    <div class="mt-6">
      <EmptyState
        v-if="!accountingInstalled"
        title="Accounting is not installed"
        description="Invoicing requires the Accounting module. Enable it for this tenant to bill customers and see invoices here."
        :icon="Receipt"
      />

      <DataTable
        v-else
        :columns="columns"
        :items="invoices.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.invoices"
        search-placeholder="Search invoice # or customer…"
        :filter-fields="filterFields"
        export-filename="sales-invoices"
        status-rail-key="status"
        :total="invoices.total"
        :from="invoices.from"
        :to="invoices.to"
        :links="invoices.links"
        empty-title="No invoices found"
        empty-description="Invoices appear here once a Sales Order or recurring contract is billed through Accounting."
      >
        <template #cell-invoice_no="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as InvoiceItem).invoice_no }}</span>
        </template>

        <template #cell-customer="{ item }">
          <span class="font-medium text-ink-900">{{ (item as InvoiceItem).customer?.name ?? '-' }}</span>
        </template>

        <template #cell-source="{ item }">
          <Link
            v-if="(item as InvoiceItem).subject_type === 'sales.so_hdrs' && (item as InvoiceItem).subject_id"
            :href="route('sales.orders.show', (item as InvoiceItem).subject_id!)"
            class="text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Order
          </Link>
          <span v-else class="text-ink-400">Recurring</span>
        </template>

        <template #cell-issue_date="{ item }">
          <span class="text-ink-700">{{ formatDate((item as InvoiceItem).issue_date) }}</span>
        </template>

        <template #cell-due_date="{ item }">
          <span class="text-ink-700">{{ formatDate((item as InvoiceItem).due_date) }}</span>
        </template>

        <template #cell-total_amount="{ item }">
          <span class="font-mono text-ink-900">{{ formatCurrency((item as InvoiceItem).total_amount, (item as InvoiceItem).currency_code) }}</span>
        </template>

        <template #cell-open_balance="{ item }">
          <span class="font-mono" :class="(item as InvoiceItem).open_balance > 0 ? 'text-signal-warning' : 'text-ink-500'">
            {{ formatCurrency((item as InvoiceItem).open_balance, (item as InvoiceItem).currency_code) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as InvoiceItem).status" />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
