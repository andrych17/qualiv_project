<!-- ponytail: Shipment listing (§3P). -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ShipmentRow {
  id: number
  warehouse_name: string | null
  carrier: string | null
  tracking_number: string | null
  ship_date_formatted: string | null
  package_count: number
  status: 'pending' | 'shipped' | 'delivered'
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
  shipments: PaginatedData<ShipmentRow>
  filters: { warehouse_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ warehouse_id: props.filters.warehouse_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.shipments.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'warehouse_id', label: 'Warehouse', type: 'select', options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })) },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'Shipped', value: 'shipped' },
      { label: 'Delivered', value: 'delivered' },
    ],
  },
]

const columns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'carrier', label: 'Carrier' },
  { key: 'tracking_number', label: 'Tracking #' },
  { key: 'package_count', label: 'Packages' },
  { key: 'status', label: 'Status' },
  { key: 'ship_date_formatted', label: 'Ship date', sortKey: 'ship_date', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.shipments.index'), {
    warehouse_id: filters.value.warehouse_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ShipmentRow | Record<string, unknown>) => {
  const row = item as ShipmentRow
  confirm({
    title: `Delete shipment #${row.id}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.shipments.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Shipments" description="Links one or more packages, carrier, and tracking — ship-confirm deducts stock.">
      <template #actions>
        <PrimaryButton :href="route('inventory.shipments.create')">New Shipment</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="shipments" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="shipments.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.shipments"
        :filter-fields="filterFields"
        export-filename="inventory-shipments"
        :total="shipments.total"
        :from="shipments.from"
        :to="shipments.to"
        :links="shipments.links"
        empty-title="No shipments yet"
        empty-description="Create a shipment and attach packed packages to it."
      >
        <template #cell-carrier="{ item }">
          <span>{{ (item as ShipmentRow).carrier ?? '—' }}</span>
        </template>
        <template #cell-tracking_number="{ item }">
          <span>{{ (item as ShipmentRow).tracking_number ?? '—' }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ShipmentRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.shipments.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as ShipmentRow).status === 'pending' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as ShipmentRow).status === 'pending'"
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
