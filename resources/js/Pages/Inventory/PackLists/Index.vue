<!-- ponytail: Pack List listing (§3P) — no bare create button; a package is always started
     from a pick list's Show page ("Create package"). -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PackListRow {
  id: number
  warehouse_name: string | null
  pick_list_id: number
  shipment_id: number | null
  package_type: 'carton' | 'pallet'
  weight: number | null
  weight_uom: string | null
  line_count: number
  status: 'packed' | 'shipped'
  packed_at_formatted: string | null
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
  packLists: PaginatedData<PackListRow>
  filters: { warehouse_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ warehouse_id: props.filters.warehouse_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.packLists.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'warehouse_id', label: 'Warehouse', type: 'select', options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })) },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Packed', value: 'packed' },
      { label: 'Shipped', value: 'shipped' },
    ],
  },
]

const columns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'pick_list_id', label: 'Pick List' },
  { key: 'package_type', label: 'Type' },
  { key: 'weight', label: 'Weight' },
  { key: 'line_count', label: 'Lines' },
  { key: 'status', label: 'Status' },
  { key: 'packed_at_formatted', label: 'Packed', sortKey: 'packed_at', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.packLists.index'), {
    warehouse_id: filters.value.warehouse_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PackListRow | Record<string, unknown>) => {
  const row = item as PackListRow
  confirm({
    title: `Delete package #${row.id}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.packLists.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Pack Lists" description="Packages built from picked lines — captures weight/dimensions before shipping." />

    <InventorySubNav active="packLists" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="packLists.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.packLists"
        :filter-fields="filterFields"
        export-filename="inventory-pack-lists"
        :total="packLists.total"
        :from="packLists.from"
        :to="packLists.to"
        :links="packLists.links"
        empty-title="No packages yet"
        empty-description="Open a ready-for-packing pick list and use 'Create package'."
      >
        <template #cell-pick_list_id="{ item }">
          <Link :href="route('inventory.pickLists.show', (item as PackListRow).pick_list_id)" class="text-accent hover:underline">
            #{{ (item as PackListRow).pick_list_id }}
          </Link>
        </template>
        <template #cell-weight="{ item }">
          <span v-if="(item as PackListRow).weight !== null">{{ (item as PackListRow).weight }} {{ (item as PackListRow).weight_uom }}</span>
          <span v-else class="text-ink-600">—</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as PackListRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.packLists.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as PackListRow).shipment_id ? 'View' : 'Edit' }}
            </Link>
            <button
              v-if="!(item as PackListRow).shipment_id"
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
