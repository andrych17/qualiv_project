<!-- ponytail: KPI Actuals listing (§3D) — MVP manual entry, one row per kpi/subject/period. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { formatNumber } from '@/Utils/formatters'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface KpiValueRow {
  id: number
  kpi_name: string | null
  kpi_unit: string | null
  subject_label: string
  period_label: string | null
  actual_value: number
  source: string
  entered_by_name: string | null
  entered_at_formatted: string | null
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
  values: PaginatedData<KpiValueRow>
  filters: { kpi_id?: string; period_id?: string; subject_type?: string; sort?: string; direction?: string; per_page?: string }
  kpis: Array<{ id: number; name: string }>
  periods: Array<{ id: number; label: string }>
}>()

const filters = ref({
  kpi_id: props.filters.kpi_id ?? '',
  period_id: props.filters.period_id ?? '',
  subject_type: props.filters.subject_type ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.values.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'kpi_id', label: 'KPI', type: 'select', options: props.kpis.map((k) => ({ label: k.name, value: String(k.id) })) },
  { key: 'period_id', label: 'Period', type: 'select', options: props.periods.map((p) => ({ label: p.label, value: String(p.id) })) },
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
  { key: 'kpi_name', label: 'KPI' },
  { key: 'subject_label', label: 'Subject' },
  { key: 'period_label', label: 'Period' },
  { key: 'actual_value', label: 'Actual', align: 'right' as const },
  { key: 'entered_by_name', label: 'Entered by' },
  { key: 'entered_at_formatted', label: 'Entered', sortKey: 'entered_at', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('performance.kpiValues.index'), {
    kpi_id: filters.value.kpi_id,
    period_id: filters.value.period_id,
    subject_type: filters.value.subject_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: KpiValueRow | Record<string, unknown>) => {
  const row = item as KpiValueRow
  confirm({
    title: `Delete this actual value?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.kpiValues.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="KPI Actuals" description="Manual entry — pick a KPI + subject + period, enter this period's number.">
      <template #actions>
        <PrimaryButton :href="route('performance.kpiValues.create')">Record actual</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="kpiValues" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="values.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="performance.kpiValues"
        :filter-fields="filterFields"
        export-filename="performance-kpi-actuals"
        :total="values.total"
        :from="values.from"
        :to="values.to"
        :links="values.links"
        empty-title="No actuals recorded yet"
        empty-description="Record this period's actual value for a KPI."
      >
        <template #cell-actual_value="{ item }">{{ formatNumber((item as KpiValueRow).actual_value) }} {{ (item as KpiValueRow).kpi_unit }}</template>
        <template #cell-entered_by_name="{ item }">
          <span>{{ (item as KpiValueRow).entered_by_name ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.kpiValues.edit', item.id)"
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
