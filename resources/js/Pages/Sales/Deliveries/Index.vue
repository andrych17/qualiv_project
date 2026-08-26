<!-- Deliveries List (§3H) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'

interface DeliveryLine {
  qty_shipped: number
  sales_order_line: { description: string } | null
}

interface DeliveryItem {
  id: number
  uuid: string
  status: string
  carrier: string | null
  tracking_number: string | null
  shipped_at: string | null
  delivered_at: string | null
  created_at: string
  order: {
    id: number
    so_number: string
    customer: { id: number; name: string } | null
  } | null
  lines: DeliveryLine[]
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
  deliveries: PaginatedData<DeliveryItem>
  statuses: string[]
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.deliveries.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.toUpperCase(), value: st })),
  },
]

const columns = [
  { key: 'delivery', label: 'Delivery UUID' },
  { key: 'order', label: 'Sales Order #' },
  { key: 'customer', label: 'Customer' },
  { key: 'carrier', label: 'Carrier & Tracking' },
  { key: 'items_count', label: 'Items' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.deliveries.index'), {
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
      title="Deliveries & Fulfillment"
      description="Pick, pack, ship, and track customer delivery notes with stock issue integration (§3H)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.deliveries.create')">New Delivery</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="deliveries" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="deliveries.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.deliveries"
        search-placeholder="Search carrier, tracking #, or SO…"
        :filter-fields="filterFields"
        export-filename="sales-deliveries"
        status-rail-key="status"
        :total="deliveries.total"
        :from="deliveries.from"
        :to="deliveries.to"
        :links="deliveries.links"
        empty-title="No deliveries found"
        empty-description="Create a delivery order from an approved sales order to begin fulfillment."
      >
        <template #cell-delivery="{ item }">
          <Link
            :href="route('sales.deliveries.show', item.id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as DeliveryItem).uuid.slice(0, 8) }}…
          </Link>
        </template>

        <template #cell-order="{ item }">
          <Link
            v-if="(item as DeliveryItem).order"
            :href="route('sales.orders.show', (item as DeliveryItem).order!.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as DeliveryItem).order!.so_number }}
          </Link>
          <span v-else class="text-ink-400">-</span>
        </template>

        <template #cell-customer="{ item }">
          <span class="text-ink-900">{{ (item as DeliveryItem).order?.customer?.name ?? '-' }}</span>
        </template>

        <template #cell-carrier="{ item }">
          <span v-if="(item as DeliveryItem).carrier" class="text-xs text-ink-600">
            {{ (item as DeliveryItem).carrier }} ({{ (item as DeliveryItem).tracking_number ?? 'No tracking' }})
          </span>
          <span v-else class="text-xs text-ink-400">Manual / Pickup</span>
        </template>

        <template #cell-items_count="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ (item as DeliveryItem).lines.reduce((s, l) => s + Number(l.qty_shipped), 0) }} units
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as DeliveryItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.deliveries.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Manage &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
