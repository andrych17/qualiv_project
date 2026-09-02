<!-- ponytail: Production Order listing (MES_SPECS.md §3A) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { formatNumber } from '@/Utils/formatters'

interface ProdOrderRow {
  id: number
  order_number: string
  product_sku: string | null
  product_name: string | null
  production_model: string
  qty: number
  uom_code: string | null
  planned_start: string | null
  status: string
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
  orders: PaginatedData<ProdOrderRow>
  filters: { search?: string; production_model?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ production_model: props.filters.production_model ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.orders.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'production_model',
    label: 'Model',
    type: 'select',
    options: [
      { label: 'Assembly', value: 'assembly' },
      { label: 'Process', value: 'process' },
    ],
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Released', value: 'released' },
      { label: 'In Progress', value: 'in_progress' },
      { label: 'Paused', value: 'paused' },
      { label: 'Completed', value: 'completed' },
      { label: 'Cancelled', value: 'cancelled' },
    ],
  },
]

const columns = [
  { key: 'order_number', label: 'Order #', sortable: true },
  { key: 'product_sku', label: 'Product' },
  { key: 'production_model', label: 'Model' },
  { key: 'qty', label: 'Qty', align: 'right' as const },
  { key: 'planned_start', label: 'Planned Start', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('mes.prodOrders.index'), {
    search: search.value,
    production_model: filters.value.production_model,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Production Orders"
      description="One header for both production models — assembly (discrete) and process (continuous)."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.prodOrders.create')">Add Production Order</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="orders.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.prodOrders"
        search-placeholder="Search order # or product…"
        :filter-fields="filterFields"
        export-filename="mes-production-orders"
        :total="orders.total"
        :from="orders.from"
        :to="orders.to"
        :links="orders.links"
        empty-title="No production orders yet"
        empty-description="Add a production order to plan an assembly or process production run."
      >
        <template #cell-order_number="{ item }">
          <Link
            :href="route('mes.prodOrders.show', (item as ProdOrderRow).id)"
            class="font-mono text-xs font-medium text-accent hover:underline"
          >
            {{ (item as ProdOrderRow).order_number }}
          </Link>
        </template>
        <template #cell-product_sku="{ item }">
          <span class="text-xs text-ink-600">{{ (item as ProdOrderRow).product_sku }} — {{ (item as ProdOrderRow).product_name }}</span>
        </template>
        <template #cell-production_model="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as ProdOrderRow).production_model }}</span>
        </template>
        <template #cell-qty="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ formatNumber((item as ProdOrderRow).qty) }} {{ (item as ProdOrderRow).uom_code }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ProdOrderRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('mes.prodOrders.show', (item as ProdOrderRow).id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
