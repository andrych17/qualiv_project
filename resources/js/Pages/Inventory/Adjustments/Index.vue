<!-- ponytail: Adjustment list (§3G) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface AdjustmentRow {
  id: number
  warehouse_name: string | null
  reason_name: string | null
  adjustment_date_formatted: string | null
  line_count: number
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
  adjustments: PaginatedData<AdjustmentRow>
  filters: { status?: string; warehouse_id?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ status: props.filters.status ?? '', warehouse_id: props.filters.warehouse_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.adjustments.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Posted', value: 'posted' },
    ],
  },
  {
    key: 'warehouse_id',
    label: 'Warehouse',
    type: 'select',
    options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })),
  },
]

const columns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'reason_name', label: 'Reason' },
  { key: 'adjustment_date_formatted', label: 'Date', sortable: true, sortKey: 'adjustment_date' },
  { key: 'line_count', label: 'Lines', align: 'right' as const },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.adjustments.index'), {
    status: filters.value.status,
    warehouse_id: filters.value.warehouse_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: AdjustmentRow | Record<string, unknown>) => {
  const row = item as AdjustmentRow
  confirm({
    title: `Delete this draft adjustment?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.adjustments.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Adjustments" description="Correct on-hand quantity — count variance, damage, write-off.">
      <template #actions>
        <PrimaryButton :href="route('inventory.adjustments.create')">New adjustment</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="adjustments" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="adjustments.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.adjustments"
        :filter-fields="filterFields"
        export-filename="inventory-adjustments"
        :total="adjustments.total"
        :from="adjustments.from"
        :to="adjustments.to"
        :links="adjustments.links"
        empty-title="No adjustments yet"
        empty-description="Create your first Adjustment to correct on-hand quantity."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as AdjustmentRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.adjustments.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as AdjustmentRow).status === 'draft' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as AdjustmentRow).status === 'draft'"
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
