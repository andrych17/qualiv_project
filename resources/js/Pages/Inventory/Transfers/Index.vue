<!-- ponytail: Transfer list (§3F) -->
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

interface TransferRow {
  id: number
  source_warehouse_name: string | null
  destination_warehouse_name: string | null
  transfer_date_formatted: string | null
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
  transfers: PaginatedData<TransferRow>
  filters: { status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.transfers.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'In transit', value: 'in_transit' },
      { label: 'Completed', value: 'completed' },
    ],
  },
]

const columns = [
  { key: 'source_warehouse_name', label: 'Source' },
  { key: 'destination_warehouse_name', label: 'Destination' },
  { key: 'transfer_date_formatted', label: 'Date', sortable: true, sortKey: 'transfer_date' },
  { key: 'line_count', label: 'Lines', align: 'right' as const },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.transfers.index'), {
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: TransferRow | Record<string, unknown>) => {
  const row = item as TransferRow
  confirm({
    title: `Delete this draft transfer?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.transfers.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Transfers" description="Move stock between locations or warehouses.">
      <template #actions>
        <PrimaryButton :href="route('inventory.transfers.create')">New transfer</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="transfers" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="transfers.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.transfers"
        :filter-fields="filterFields"
        export-filename="inventory-transfers"
        :total="transfers.total"
        :from="transfers.from"
        :to="transfers.to"
        :links="transfers.links"
        empty-title="No transfers yet"
        empty-description="Create your first Transfer to move stock between locations."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as TransferRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.transfers.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as TransferRow).status === 'draft' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as TransferRow).status === 'draft'"
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
