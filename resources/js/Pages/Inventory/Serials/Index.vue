<!-- ponytail: Serial Number listing (§3M) — read-only lookup, not master-data CRUD: every row
     here was created by a Goods Receipt line (SerialService::receive()), never by hand. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface SerialRow {
  id: number
  serial_number: string
  product_sku: string | null
  product_name: string | null
  status: 'in_stock' | 'reserved' | 'issued'
  warehouse_name: string | null
  location_code: string | null
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
  serials: PaginatedData<SerialRow>
  filters: { search?: string; product_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  products: Array<{ id: number; sku: string; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ product_id: props.filters.product_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.serials.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'product_id',
    label: 'Product',
    type: 'select',
    options: props.products.map((p) => ({ label: `${p.sku} — ${p.name}`, value: String(p.id) })),
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'In stock', value: 'in_stock' },
      { label: 'Issued', value: 'issued' },
    ],
  },
]

const columns = [
  { key: 'serial_number', label: 'Serial #', sortable: true },
  { key: 'product_sku', label: 'Product' },
  { key: 'status', label: 'Status' },
  { key: 'location_code', label: 'Location' },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('inventory.serials.index'), {
    search: search.value,
    product_id: filters.value.product_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader title="Serial Numbers" description="Every unit of a serial-tracked product — created automatically by a Goods Receipt line." />

    <InventorySubNav active="serials" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="serials.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.serials"
        search-placeholder="Search serial number…"
        :filter-fields="filterFields"
        export-filename="inventory-serials"
        :total="serials.total"
        :from="serials.from"
        :to="serials.to"
        :links="serials.links"
        empty-title="No serials yet"
        empty-description="Serial numbers are created automatically when a serial-tracked product is received — post a Goods Receipt to see them here."
      >
        <template #cell-serial_number="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as SerialRow).serial_number }}</span>
        </template>
        <template #cell-product_sku="{ item }">
          <span class="text-ink-900">{{ (item as SerialRow).product_sku }}</span>
          <span class="block text-xs text-ink-600">{{ (item as SerialRow).product_name }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as SerialRow).status" />
        </template>
        <template #cell-location_code="{ item }">
          <span v-if="(item as SerialRow).location_code">{{ (item as SerialRow).warehouse_name }} / {{ (item as SerialRow).location_code }}</span>
          <span v-else class="text-ink-600">—</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
