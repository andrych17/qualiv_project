<!-- ponytail: Batch / Lot listing (§3L) — most rows are created implicitly by a Goods Receipt
     line's lot number, not here; this screen is for pre-registering/correcting a lot and for
     the expiring-soon Status Rail, which stands in for §3A's Dashboard integration since that
     page doesn't exist yet. -->
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

interface BatchRow {
  id: number
  batch_number: string
  product_sku: string | null
  product_name: string | null
  expiry_date_formatted: string | null
  manufacture_date_formatted: string | null
  supplier_reference: string | null
  status_rail: '' | 'danger' | 'warning' | 'success'
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
  batches: PaginatedData<BatchRow>
  filters: { search?: string; product_id?: string; expiry_status?: string; sort?: string; direction?: string; per_page?: string }
  products: Array<{ id: number; sku: string; name: string }>
  warningDays: number
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ product_id: props.filters.product_id ?? '', expiry_status: props.filters.expiry_status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.batches.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'product_id',
    label: 'Product',
    type: 'select',
    options: props.products.map((p) => ({ label: `${p.sku} — ${p.name}`, value: String(p.id) })),
  },
  {
    key: 'expiry_status',
    label: 'Expiry',
    type: 'select',
    options: [
      { label: 'Expiring soon', value: 'expiring_soon' },
      { label: 'Expired', value: 'expired' },
    ],
  },
]

const columns = [
  { key: 'batch_number', label: 'Lot #', sortable: true },
  { key: 'product_sku', label: 'Product' },
  { key: 'expiry_date_formatted', label: 'Expiry', sortKey: 'expiry_date', sortable: true },
  { key: 'manufacture_date_formatted', label: 'Manufactured' },
  { key: 'supplier_reference', label: 'Supplier ref' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

// StatusBadge's color map keys off known domain statuses, not raw signal names — map the
// rail value to one that already resolves to the right color (overdue=red, pending=yellow,
// active=green), same trick DataTable's own statusRailKey border uses independently.
const railBadge = (rail: BatchRow['status_rail']): { status: string; label: string } | null => {
  if (rail === 'danger') return { status: 'overdue', label: 'Expired' }
  if (rail === 'warning') return { status: 'pending', label: 'Expiring soon' }
  if (rail === 'success') return { status: 'active', label: 'OK' }
  return null
}

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('inventory.batches.index'), {
    search: search.value,
    product_id: filters.value.product_id,
    expiry_status: filters.value.expiry_status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: BatchRow | Record<string, unknown>) => {
  const row = item as BatchRow
  confirm({
    title: `Delete lot ${row.batch_number}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.batches.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Batches / Lots" :description="`Expiring-soon threshold: ${warningDays} day(s), tenant-editable via Constants.`">
      <template #actions>
        <PrimaryButton :href="route('inventory.batches.create')">Add batch</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="batches" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="batches.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        status-rail-key="status_rail"
        sticky-header
        storage-key="inventory.batches"
        search-placeholder="Search lot number…"
        :filter-fields="filterFields"
        export-filename="inventory-batches"
        :total="batches.total"
        :from="batches.from"
        :to="batches.to"
        :links="batches.links"
        empty-title="No batches yet"
        empty-description="Batches are usually created automatically from a Goods Receipt lot number — add one here to pre-register it."
      >
        <template #cell-batch_number="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as BatchRow).batch_number }}</span>
        </template>
        <template #cell-product_sku="{ item }">
          <span class="text-ink-900">{{ (item as BatchRow).product_sku }}</span>
          <span class="block text-xs text-ink-600">{{ (item as BatchRow).product_name }}</span>
        </template>
        <template #cell-expiry_date_formatted="{ item }">
          <div class="flex items-center gap-2">
            <span>{{ (item as BatchRow).expiry_date_formatted ?? '—' }}</span>
            <StatusBadge v-if="railBadge((item as BatchRow).status_rail)" :status="railBadge((item as BatchRow).status_rail)!.status" :label="railBadge((item as BatchRow).status_rail)!.label" />
          </div>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.batches.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
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
