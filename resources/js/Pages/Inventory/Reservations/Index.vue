<!-- ponytail: Reservation listing (§3N) — read-only browse + manual release, not master-data
     CRUD: every row here is created by InventoryService::reserve() (a future caller, e.g.
     Sales order-confirm — not built yet), never by hand. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ReservationRow {
  id: number
  product_sku: string | null
  product_name: string | null
  qty: number
  warehouse_name: string | null
  location_code: string | null
  batch_number: string | null
  serial_number: string | null
  subject_type: string | null
  subject_id: string | null
  status: 'active' | 'fulfilled' | 'released'
  is_expired: boolean
  expires_at_formatted: string | null
  created_at_formatted: string | null
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
  reservations: PaginatedData<ReservationRow>
  filters: { product_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  products: Array<{ id: number; sku: string; name: string }>
}>()

const filters = ref({ product_id: props.filters.product_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.reservations.per_page)

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
      { label: 'Active', value: 'active' },
      { label: 'Fulfilled', value: 'fulfilled' },
      { label: 'Released', value: 'released' },
    ],
  },
]

const columns = [
  { key: 'product_sku', label: 'Product' },
  { key: 'qty', label: 'Qty', sortable: true },
  { key: 'location_code', label: 'Location' },
  { key: 'lot_serial', label: 'Lot / Serial' },
  { key: 'subject', label: 'Requested by' },
  { key: 'status', label: 'Status' },
  { key: 'expires_at_formatted', label: 'Expires', sortKey: 'expires_at', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.reservations.index'), {
    product_id: filters.value.product_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmRelease = (item: ReservationRow | Record<string, unknown>) => {
  const row = item as ReservationRow
  confirm({
    title: `Release this reservation of ${row.qty} × ${row.product_sku}?`,
    variant: 'destructive',
    confirmText: 'Release',
    onConfirm: () => router.patch(route('inventory.reservations.release', row.id)),
  })
}

// §3O: the actual pick-list creation trigger — see PickListService::generate(). Any
// non-active rows in the selection are silently skipped server-side.
const generatePickList = () => {
  router.post(route('inventory.reservations.generatePickList'), { ids: selected.value }, {
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Reservations" description="Soft holds against on-hand stock — created by a caller (e.g. a future Sales order-confirm), never by hand here." />

    <InventorySubNav active="reservations" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="reservations.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.reservations"
        :filter-fields="filterFields"
        export-filename="inventory-reservations"
        :total="reservations.total"
        :from="reservations.from"
        :to="reservations.to"
        :links="reservations.links"
        empty-title="No reservations yet"
        empty-description="Reservations are created automatically by a caller promising stock to an order — none have been made yet."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="generatePickList"
          >
            Generate pick list
          </button>
        </template>
        <template #cell-product_sku="{ item }">
          <span class="text-ink-900">{{ (item as ReservationRow).product_sku }}</span>
          <span class="block text-xs text-ink-600">{{ (item as ReservationRow).product_name }}</span>
        </template>
        <template #cell-qty="{ item }">
          <span class="font-mono text-sm text-ink-900">{{ (item as ReservationRow).qty }}</span>
        </template>
        <template #cell-location_code="{ item }">
          <span v-if="(item as ReservationRow).location_code">{{ (item as ReservationRow).warehouse_name }} / {{ (item as ReservationRow).location_code }}</span>
          <span v-else class="text-ink-600">{{ (item as ReservationRow).warehouse_name }} (unassigned)</span>
        </template>
        <template #cell-lot_serial="{ item }">
          <span v-if="(item as ReservationRow).serial_number" class="font-mono text-xs">{{ (item as ReservationRow).serial_number }}</span>
          <span v-else-if="(item as ReservationRow).batch_number" class="font-mono text-xs">{{ (item as ReservationRow).batch_number }}</span>
          <span v-else class="text-ink-600">—</span>
        </template>
        <template #cell-subject="{ item }">
          <span v-if="(item as ReservationRow).subject_type" class="text-xs text-ink-600">{{ (item as ReservationRow).subject_type }} #{{ (item as ReservationRow).subject_id }}</span>
          <span v-else class="text-ink-600">—</span>
        </template>
        <template #cell-status="{ item }">
          <div class="flex items-center gap-1.5">
            <StatusBadge :status="(item as ReservationRow).status" />
            <StatusBadge v-if="(item as ReservationRow).is_expired" status="overdue" label="Expired, pending sweep" />
          </div>
        </template>
        <template #cell-actions="{ item }">
          <button
            v-if="(item as ReservationRow).status === 'active'"
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmRelease(item)"
          >
            Release
          </button>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
