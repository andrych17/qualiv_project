<!-- ponytail: Production Event Ledger, global read-only view (MES_SPECS.md §3C) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface ProdEventRow {
  id: number
  order_id: number
  order_number: string | null
  event_type: string
  payload: Record<string, unknown> | null
  occurred_at: string | null
  user_name: string | null
  machine_code: string | null
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
  events: PaginatedData<ProdEventRow>
  filters: { search?: string; event_type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ event_type: props.filters.event_type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.events.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'event_type',
    label: 'Event Type',
    type: 'select',
    options: [
      { label: 'Order Released', value: 'order_released' },
      { label: 'Material Issued', value: 'material_issued' },
      { label: 'Material Returned', value: 'material_returned' },
      { label: 'Operation Started', value: 'operation_started' },
      { label: 'Operation Paused', value: 'operation_paused' },
      { label: 'Operation Completed', value: 'operation_completed' },
      { label: 'Machine Started', value: 'machine_started' },
      { label: 'Machine Stopped', value: 'machine_stopped' },
      { label: 'Parameter Recorded', value: 'parameter_recorded' },
      { label: 'QC Sample Taken', value: 'qc_sample_taken' },
      { label: 'Scrap Recorded', value: 'scrap_recorded' },
      { label: 'Output Produced', value: 'output_produced' },
      { label: 'Downtime Started', value: 'downtime_started' },
      { label: 'Downtime Ended', value: 'downtime_ended' },
      { label: 'Batch Split', value: 'batch_split' },
      { label: 'Batch Merged', value: 'batch_merged' },
    ],
  },
]

const columns = [
  { key: 'occurred_at', label: 'Occurred At', sortable: true },
  { key: 'order_number', label: 'Order #' },
  { key: 'event_type', label: 'Event' },
  { key: 'payload', label: 'Payload' },
  { key: 'user_name', label: 'User' },
  { key: 'machine_code', label: 'Machine' },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('mes.prodEvents.index'), {
    search: search.value,
    event_type: filters.value.event_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Production Events"
      description="Append-only ledger of every execution-significant action (MES_SPECS.md §3C) — the single source every other MES engine derives from. System-written only."
    />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="events.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.prodEvents"
        search-placeholder="Search order #…"
        :filter-fields="filterFields"
        export-filename="mes-production-events"
        :total="events.total"
        :from="events.from"
        :to="events.to"
        :links="events.links"
        empty-title="No events recorded yet"
        empty-description="Events appear here as production orders are released and, later, executed on the shop floor."
      >
        <template #cell-order_number="{ item }">
          <Link
            :href="route('mes.prodOrders.show', (item as ProdEventRow).order_id)"
            class="font-mono text-xs font-medium text-accent hover:underline"
          >
            {{ (item as ProdEventRow).order_number }}
          </Link>
        </template>
        <template #cell-event_type="{ item }">
          <StatusBadge :status="(item as ProdEventRow).event_type" />
        </template>
        <template #cell-payload="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ProdEventRow).payload ? JSON.stringify((item as ProdEventRow).payload) : '—' }}</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
