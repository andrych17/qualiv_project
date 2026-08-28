<!-- ponytail: Period listing (§3C/§4) — shared master used by Targets and later Budgeting/Forecast/OKR cycles. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PeriodRow {
  id: number
  label: string
  period_type: 'year' | 'quarter' | 'month'
  start_date_formatted: string
  end_date_formatted: string
  is_active: boolean
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
  periods: PaginatedData<PeriodRow>
  filters: { period_type?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ period_type: props.filters.period_type ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.periods.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'period_type',
    label: 'Type',
    type: 'select',
    options: [
      { label: 'Year', value: 'year' },
      { label: 'Quarter', value: 'quarter' },
      { label: 'Month', value: 'month' },
    ],
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Inactive', value: 'inactive' },
    ],
  },
]

const columns = [
  { key: 'label', label: 'Label', sortable: true },
  { key: 'period_type', label: 'Type' },
  { key: 'start_date_formatted', label: 'Start', sortKey: 'start_date', sortable: true },
  { key: 'end_date_formatted', label: 'End' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('performance.periods.index'), {
    period_type: filters.value.period_type,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PeriodRow | Record<string, unknown>) => {
  const row = item as PeriodRow
  confirm({
    title: `Delete period ${row.label}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.periods.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected period(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('performance.periods.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Periods" description="Fiscal period slices shared by Targets and every other Performance engine.">
      <template #actions>
        <PrimaryButton :href="route('performance.periods.create')">Add period</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="periods" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="periods.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="performance.periods"
        :filter-fields="filterFields"
        export-filename="performance-periods"
        :total="periods.total"
        :from="periods.from"
        :to="periods.to"
        :links="periods.links"
        empty-title="No periods yet"
        empty-description="Add your first fiscal period."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDelete"
          >
            Delete selected
          </button>
        </template>
        <template #cell-period_type="{ item }"><StatusBadge :status="(item as PeriodRow).period_type" /></template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as PeriodRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.periods.edit', item.id)"
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
