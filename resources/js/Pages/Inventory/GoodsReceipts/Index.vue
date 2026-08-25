<!-- ponytail: Goods Receipt list (§3D) -->
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

interface ReceiptRow {
  id: number
  reference_number: string | null
  warehouse_name: string | null
  receipt_date_formatted: string | null
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
  receipts: PaginatedData<ReceiptRow>
  filters: { search?: string; status?: string; warehouse_id?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', warehouse_id: props.filters.warehouse_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.receipts.per_page)

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
  { key: 'reference_number', label: 'Reference' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'receipt_date_formatted', label: 'Date', sortable: true, sortKey: 'receipt_date' },
  { key: 'line_count', label: 'Lines', align: 'right' as const },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.goodsReceipts.index'), {
    search: search.value,
    status: filters.value.status,
    warehouse_id: filters.value.warehouse_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ReceiptRow | Record<string, unknown>) => {
  const row = item as ReceiptRow
  confirm({
    title: `Delete this draft receipt?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.goodsReceipts.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Goods Receipts" description="Receive stock in — from a vendor, a PO, or an opening balance.">
      <template #actions>
        <PrimaryButton :href="route('inventory.goodsReceipts.create')">New receipt</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="goodsReceipts" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="receipts.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.goodsReceipts"
        search-placeholder="Search reference number…"
        :filter-fields="filterFields"
        export-filename="inventory-goods-receipts"
        :total="receipts.total"
        :from="receipts.from"
        :to="receipts.to"
        :links="receipts.links"
        empty-title="No receipts yet"
        empty-description="Create your first Goods Receipt to bring stock on hand."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ReceiptRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.goodsReceipts.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as ReceiptRow).status === 'draft' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as ReceiptRow).status === 'draft'"
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
