<!-- ponytail: POS Returns & Refunds (POS_SPECS.md §3L) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'
import { debounce } from '@/Composables/debounce'

interface ReturnRow {
  id: number
  return_no: string
  original_txn_id: number
  total_refund: number
  refund_method: string
  reason_code: string
  status: string
  created_at: string
  original_transaction?: { id: number; txn_no: string }
  session?: { terminal?: { name: string } }
  lines?: Array<{ id: number; description: string; return_qty: number; line_refund: number }>
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
  returns: PaginatedData<ReturnRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
})

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)

const perPage = ref(Number(props.filters.per_page) || props.returns.per_page)

const columns = [
  { key: 'return_no', label: 'No. Retur', sortable: true },
  { key: 'original_transaction', label: 'Struk Asal' },
  { key: 'terminal', label: 'Terminal' },
  { key: 'reason_code', label: 'Alasan Retur' },
  { key: 'refund_method', label: 'Metode Refund' },
  { key: 'total_refund', label: 'Nilai Refund', align: 'right' as const },
  { key: 'created_at', label: 'Tanggal', sortable: true },
  { key: 'status', label: 'Status' },
]

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Completed', value: 'completed' },
      { label: 'Pending', value: 'pending' },
      { label: 'Cancelled', value: 'cancelled' },
    ],
  },
]

const applyFilters = () => {
  router.get(
    route('pos.returns.index'),
    {
      search: search.value || undefined,
      status: filters.value.status || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true },
  )
}

watch(search, debounce(applyFilters, 300))
watch(filters, applyFilters, { deep: true })
watch(sort, applyFilters)
watch(perPage, applyFilters)
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Retur & Pengembalian Dana Kasir"
      description="Pencatatan retur barang kasir, pembalikan stok inventaris, dan pengembalian kas/kartu."
    />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="returns.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        :filter-fields="filterFields"
        :total="returns.total"
        :from="returns.from"
        :to="returns.to"
        :links="returns.links"
        empty-title="Belum ada transaksi retur"
        empty-description="Transaksi retur dan pengembalian dana akan tercatat di sini."
      >
        <template #cell-return_no="{ item }">
          <span class="font-mono font-medium text-ink-900">{{ (item as ReturnRow).return_no }}</span>
        </template>
        <template #cell-original_transaction="{ item }">
          <span class="font-mono text-ink-700">{{ (item as ReturnRow).original_transaction?.txn_no || '-' }}</span>
        </template>
        <template #cell-terminal="{ item }">
          <span class="text-ink-700">{{ (item as ReturnRow).session?.terminal?.name || '-' }}</span>
        </template>
        <template #cell-reason_code="{ item }">
          <span class="text-ink-700">{{ (item as ReturnRow).reason_code }}</span>
        </template>
        <template #cell-refund_method="{ item }">
          <span class="font-medium uppercase text-ink-800">{{ (item as ReturnRow).refund_method }}</span>
        </template>
        <template #cell-total_refund="{ item }">
          <span class="font-mono font-semibold text-rose-600">
            {{ formatCurrency((item as ReturnRow).total_refund) }}
          </span>
        </template>
        <template #cell-created_at="{ item }">
          <span class="text-xs text-ink-600">{{ formatDate((item as ReturnRow).created_at) }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge
            :status="(item as ReturnRow).status === 'completed' ? 'success' : 'neutral'"
            :label="(item as ReturnRow).status.toUpperCase()"
          />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
