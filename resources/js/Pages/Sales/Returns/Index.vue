<!-- Returns List (§3J) -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'

interface ReturnLine {
  qty_returned: number
}

interface ReturnItem {
  id: number
  uuid: string
  reason_code: string
  status: string
  created_at: string
  customer: { id: number; name: string } | null
  order: { id: number; so_number: string } | null
  replacement_order: { id: number; so_number: string } | null
  lines: ReturnLine[]
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
  returns: PaginatedData<ReturnItem>
  statuses: string[]
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.returns.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: props.statuses.map((st) => ({ label: st.toUpperCase(), value: st })),
  },
]

const columns = [
  { key: 'uuid', label: 'Return UUID' },
  { key: 'customer', label: 'Customer' },
  { key: 'order', label: 'Original Order' },
  { key: 'reason_code', label: 'Reason', sortable: true },
  { key: 'items_count', label: 'Items' },
  { key: 'replacement_order', label: 'Replacement SO' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('sales.returns.index'), {
    search: search.value || undefined,
    status: filters.value.status || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Returns (RMA)"
      description="Process return merchandise authorizations, refunds via credit note, and replacement sales orders (§3J)."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.returns.create')">New Return Request</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="returns" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="returns.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="sales.returns"
        search-placeholder="Search reason or customer…"
        :filter-fields="filterFields"
        export-filename="sales-returns"
        status-rail-key="status"
        :total="returns.total"
        :from="returns.from"
        :to="returns.to"
        :links="returns.links"
        empty-title="No returns found"
        empty-description="Create an RMA request to track returned goods and manage replacements."
      >
        <template #cell-uuid="{ item }">
          <Link
            :href="route('sales.returns.show', item.id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as ReturnItem).uuid.slice(0, 8) }}…
          </Link>
        </template>

        <template #cell-customer="{ item }">
          <span class="font-medium text-ink-900">{{ (item as ReturnItem).customer?.name ?? '-' }}</span>
        </template>

        <template #cell-order="{ item }">
          <Link
            v-if="(item as ReturnItem).order"
            :href="route('sales.orders.show', (item as ReturnItem).order!.id)"
            class="text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as ReturnItem).order!.so_number }}
          </Link>
          <span v-else class="text-ink-400">Direct RMA</span>
        </template>

        <template #cell-reason_code="{ item }">
          <span class="text-ink-700">{{ (item as ReturnItem).reason_code }}</span>
        </template>

        <template #cell-items_count="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ (item as ReturnItem).lines.reduce((s, l) => s + Number(l.qty_returned), 0) }} units
          </span>
        </template>

        <template #cell-replacement_order="{ item }">
          <Link
            v-if="(item as ReturnItem).replacement_order"
            :href="route('sales.orders.show', (item as ReturnItem).replacement_order!.id)"
            class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as ReturnItem).replacement_order!.so_number }}
          </Link>
          <span v-else class="text-ink-400 text-xs">-</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ReturnItem).status" />
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('sales.returns.show', item.id)"
            class="text-sm font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
