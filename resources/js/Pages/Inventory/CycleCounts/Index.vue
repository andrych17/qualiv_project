<!-- ponytail: Cycle Count listing (§3Q). -->
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

interface CycleCountRow {
  id: number
  warehouse_name: string | null
  scope: string
  assigned_to_name: string | null
  status: 'pending' | 'in_progress' | 'completed'
  line_count: number
  counted_lines_count: number
  scheduled_date_formatted: string | null
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
  counts: PaginatedData<CycleCountRow>
  filters: { warehouse_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ warehouse_id: props.filters.warehouse_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.counts.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'warehouse_id', label: 'Warehouse', type: 'select', options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })) },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Pending', value: 'pending' },
      { label: 'In progress', value: 'in_progress' },
      { label: 'Completed', value: 'completed' },
    ],
  },
]

const columns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'scope', label: 'Scope' },
  { key: 'assigned_to_name', label: 'Assigned to' },
  { key: 'progress', label: 'Progress' },
  { key: 'status', label: 'Status' },
  { key: 'scheduled_date_formatted', label: 'Scheduled', sortKey: 'scheduled_date', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.cycleCounts.index'), {
    warehouse_id: filters.value.warehouse_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: CycleCountRow | Record<string, unknown>) => {
  const row = item as CycleCountRow
  confirm({
    title: `Delete cycle count #${row.id}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.cycleCounts.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Cycle Counts" description="Scheduled counts by location, category, or ABC class — scan-to-count with live variance.">
      <template #actions>
        <PrimaryButton :href="route('inventory.cycleCounts.create')">New Cycle Count</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="cycleCounts" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="counts.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.cycleCounts"
        :filter-fields="filterFields"
        export-filename="inventory-cycle-counts"
        :total="counts.total"
        :from="counts.from"
        :to="counts.to"
        :links="counts.links"
        empty-title="No cycle counts yet"
        empty-description="Create one scoped by location, category, or ABC class."
      >
        <template #cell-assigned_to_name="{ item }">
          <span>{{ (item as CycleCountRow).assigned_to_name ?? '—' }}</span>
        </template>
        <template #cell-progress="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as CycleCountRow).counted_lines_count }} / {{ (item as CycleCountRow).line_count }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as CycleCountRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.cycleCounts.show', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Work count
            </Link>
            <button
              v-if="(item as CycleCountRow).counted_lines_count === 0"
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
