<!-- ponytail: Pick List listing (§3O) — no create button here; a pick list is generated from
     the Reservations page's "Generate pick list" bulk action (see ReservationController). -->
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

interface PickListRow {
  id: number
  warehouse_name: string | null
  assigned_to_name: string | null
  status: 'pending' | 'in_progress' | 'ready_for_packing'
  line_count: number
  picked_lines_count: number
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
  pickLists: PaginatedData<PickListRow>
  filters: { warehouse_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ warehouse_id: props.filters.warehouse_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.pickLists.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'warehouse_id', label: 'Warehouse', type: 'select', options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })) },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'In progress', value: 'in_progress' },
      { label: 'Ready for packing', value: 'ready_for_packing' },
    ],
  },
]

const columns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'assigned_to_name', label: 'Assigned to' },
  { key: 'progress', label: 'Progress' },
  { key: 'status', label: 'Status' },
  { key: 'created_at_formatted', label: 'Created', sortKey: 'created_at', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.pickLists.index'), {
    warehouse_id: filters.value.warehouse_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PickListRow | Record<string, unknown>) => {
  const row = item as PickListRow
  confirm({
    title: `Delete this pick list for ${row.warehouse_name}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.pickLists.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Pick Lists" description="Generated from active reservations — assign a picker, then work the scan-to-pick flow." />

    <InventorySubNav active="pickLists" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="pickLists.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.pickLists"
        :filter-fields="filterFields"
        export-filename="inventory-pick-lists"
        :total="pickLists.total"
        :from="pickLists.from"
        :to="pickLists.to"
        :links="pickLists.links"
        empty-title="No pick lists yet"
        empty-description="Select active reservations on the Reservations page and generate a pick list."
      >
        <template #cell-assigned_to_name="{ item }">
          <span>{{ (item as PickListRow).assigned_to_name ?? '—' }}</span>
        </template>
        <template #cell-progress="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as PickListRow).picked_lines_count }} / {{ (item as PickListRow).line_count }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as PickListRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.pickLists.show', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Work list
            </Link>
            <button
              v-if="(item as PickListRow).picked_lines_count === 0"
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
