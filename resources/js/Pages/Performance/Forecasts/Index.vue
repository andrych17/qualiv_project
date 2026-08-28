<!-- ponytail: Forecast list (§3H) — shows only the latest version of each series by default;
     "History" reveals every version of one series via the `series` filter. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ForecastRow {
  id: number
  subject_label: string
  linked_label: string
  period_label: string | null
  version_no: number
  is_latest: boolean
  lines_count: number
  series_id: number
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
  forecasts: PaginatedData<ForecastRow>
  filters: { subject_type?: string; series?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ subject_type: props.filters.subject_type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.forecasts.per_page)
const viewingSeries = computed(() => !!props.filters.series)

const filterFields: FilterFieldDef[] = [
  {
    key: 'subject_type',
    label: 'Subject',
    type: 'select',
    options: [
      { label: 'Company', value: 'company' },
      { label: 'Org Unit', value: 'org_unit' },
      { label: 'Employee', value: 'employee' },
    ],
  },
]

const columns = [
  { key: 'linked_label', label: 'Linked to' },
  { key: 'subject_label', label: 'Subject' },
  { key: 'period_label', label: 'Horizon' },
  { key: 'version_no', label: 'Version', align: 'right' as const },
  { key: 'is_latest', label: 'Status' },
  { key: 'lines_count', label: 'Lines', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('performance.forecasts.index'), {
    subject_type: filters.value.subject_type,
    series: props.filters.series,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const viewHistory = (row: ForecastRow) => {
  router.get(route('performance.forecasts.index'), { series: row.series_id }, { preserveState: true })
}

const clearHistory = () => router.get(route('performance.forecasts.index'))

const { confirm } = useConfirm()

const confirmDelete = (item: ForecastRow | Record<string, unknown>) => {
  const row = item as ForecastRow
  confirm({
    title: `Delete this forecast?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.forecasts.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Forecasts" description="Project a trajectory against a Budget or a KPI target.">
      <template #actions>
        <PrimaryButton :href="route('performance.forecasts.create')">New forecast</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="forecasts" class="mt-6" />

    <div class="mt-6 space-y-4">
      <div v-if="viewingSeries" class="flex items-center justify-between rounded-md border border-border bg-surface-50 px-3 py-2 text-sm">
        <span class="text-ink-600">Showing every version of one forecast series.</span>
        <button type="button" class="font-medium text-accent hover:underline" @click="clearHistory">Back to latest forecasts</button>
      </div>

      <DataTable
        :columns="columns"
        :items="forecasts.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="performance.forecasts"
        :filter-fields="viewingSeries ? [] : filterFields"
        export-filename="performance-forecasts"
        :total="forecasts.total"
        :from="forecasts.from"
        :to="forecasts.to"
        :links="forecasts.links"
        empty-title="No forecasts yet"
        empty-description="Create a forecast against a Budget or a KPI target."
      >
        <template #cell-is_latest="{ item }">
          <StatusBadge :status="(item as ForecastRow).is_latest ? 'active' : 'archived'" :label="(item as ForecastRow).is_latest ? 'Latest' : 'Superseded'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.forecasts.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              View
            </Link>
            <button
              v-if="(item as ForecastRow).lines_count >= 0 && !viewingSeries"
              type="button"
              class="text-sm font-medium text-ink-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="viewHistory(item as ForecastRow)"
            >
              History
            </button>
            <button
              v-if="(item as ForecastRow).version_no === 1 && (item as ForecastRow).is_latest"
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
