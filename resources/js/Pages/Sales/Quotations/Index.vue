<!-- Quotations List (§3E) -->
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
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface QuotationLine {
  line_total: number
  discount_amount: number
  tax_amount: number
}

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
  lines: QuotationLine[]
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
  quotations: PaginatedData<QuotationItem>
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
const perPage = ref(Number(props.filters.per_page) || props.quotations.per_page)

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
  { key: 'quotation', label: 'Quotation' },
  { key: 'customer', label: 'Customer' },
  { key: 'opportunity', label: 'Opportunity' },
  { key: 'revision_no', label: 'Revision', align: 'center' as const },
  { key: 'validity_date', label: 'Validity Date', sortable: true },
  { key: 'total_amount', label: 'Total Amount', align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const calculateQuoteTotal = (lines: QuotationLine[]) => {
  const subtotal = lines.reduce((acc, l) => acc + Number(l.line_total), 0)
  const discount = lines.reduce((acc, l) => acc + Number(l.discount_amount), 0)
  const tax = lines.reduce((acc, l) => acc + Number(l.tax_amount), 0)
  return subtotal - discount + tax
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.quotations.index'), {
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

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="quotations.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.quotations"
        search-placeholder="Search by customer name or UUID…"
        :filter-fields="filterFields"
        export-filename="sales-quotations"
        status-rail-key="status"
        :total="quotations.total"
        :from="quotations.from"
        :to="quotations.to"
        :links="quotations.links"
        empty-title="No quotations found"
        empty-description="Create an estimate or convert a customer lead into a quotation."
      >
        <template #cell-quotation="{ item }">
          <Link
            :href="route('sales.quotations.show', item.id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as QuotationItem).uuid.slice(0, 8) }}…
          </Link>
        </template>

        <template #cell-customer="{ item }">
          <span class="font-medium text-ink-900">{{ (item as QuotationItem).customer?.name ?? '-' }}</span>
        </template>

        <template #cell-opportunity="{ item }">
          <span class="text-ink-600">{{ (item as QuotationItem).opportunity?.name ?? '-' }}</span>
        </template>

        <template #cell-revision_no="{ item }">
          <span class="font-medium text-ink-700">Rev. {{ (item as QuotationItem).revision_no }}</span>
        </template>

        <template #cell-validity_date="{ item }">
          <span class="text-ink-600">{{ (item as QuotationItem).validity_date ? formatDate((item as QuotationItem).validity_date) : 'No expiry' }}</span>
        </template>

        <template #cell-total_amount="{ item }">
          <span class="font-mono font-semibold text-ink-900">
            {{ formatCurrency(calculateQuoteTotal((item as QuotationItem).lines)) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as QuotationItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.quotations.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
