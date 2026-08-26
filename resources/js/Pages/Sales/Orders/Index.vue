<!-- Sales Orders List (§3F) -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency } from '@/Utils/formatters'

interface OrderLine {
  line_total: number
  discount_amount: number
  tax_amount: number
  qty_ordered: number
  qty_delivered: number
  qty_invoiced: number
}

interface OrderItem {
  id: number
  uuid: string
  so_number: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  quote: { id: number; uuid: string; revision_no: number } | null
  lines: OrderLine[]
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
  orders: PaginatedData<OrderItem>
  statuses: string[]
  filters: { search?: string; status?: string; customer_id?: string; sort?: string; direction?: string; per_page?: string }
  customers: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  customer_id: props.filters.customer_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.orders.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.toUpperCase(), value: st })),
  },
  {
    key: 'customer_id',
    label: 'Customer',
    type: 'select',
    options: props.customers.map((c) => ({ label: c.name, value: String(c.id) })),
  },
]

const columns = [
  { key: 'so_number', label: 'SO Number', sortable: true },
  { key: 'customer', label: 'Customer' },
  { key: 'source', label: 'Source' },
  { key: 'total_amount', label: 'Total Amount', align: 'right' as const },
  { key: 'fulfillment', label: 'Fulfillment' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const calculateOrderTotal = (lines: OrderLine[]) => {
  const subtotal = lines.reduce((acc, l) => acc + Number(l.line_total), 0)
  const discount = lines.reduce((acc, l) => acc + Number(l.discount_amount), 0)
  const tax = lines.reduce((acc, l) => acc + Number(l.tax_amount), 0)
  return subtotal - discount + tax
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.orders.index'), {
    search: search.value || undefined,
    status: filters.value.status || undefined,
    customer_id: filters.value.customer_id || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
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

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="orders.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.orders"
        search-placeholder="Search by SO number or customer…"
        :filter-fields="filterFields"
        export-filename="sales-orders"
        status-rail-key="status"
        :total="orders.total"
        :from="orders.from"
        :to="orders.to"
        :links="orders.links"
        empty-title="No sales orders found"
        empty-description="Create your first sales order or convert from an approved quotation."
      >
        <template #cell-so_number="{ item }">
          <Link
            :href="route('sales.orders.show', item.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as OrderItem).so_number }}
          </Link>
        </template>

        <template #cell-customer="{ item }">
          <span class="font-medium text-ink-900">{{ (item as OrderItem).customer?.name ?? '-' }}</span>
        </template>

        <template #cell-source="{ item }">
          <span v-if="(item as OrderItem).quote" class="text-xs text-ink-600">
            Quote Rev. {{ (item as OrderItem).quote?.revision_no }}
          </span>
          <span v-else class="text-xs text-ink-500">Direct Order</span>
        </template>

        <template #cell-total_amount="{ item }">
          <span class="font-mono font-semibold text-ink-900">
            {{ formatCurrency(calculateOrderTotal((item as OrderItem).lines)) }}
          </span>
        </template>

        <template #cell-fulfillment="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ (item as OrderItem).lines.reduce((s, l) => s + Number(l.qty_delivered), 0) }} /
            {{ (item as OrderItem).lines.reduce((s, l) => s + Number(l.qty_ordered), 0) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as OrderItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.orders.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
