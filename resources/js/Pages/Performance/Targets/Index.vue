<!-- ponytail: Target listing (§3C) — the "multi-level" mechanism: one KPI, many subject/period rows. -->
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

interface TargetRow {
  id: number
  kpi_name: string | null
  kpi_unit: string | null
  subject_label: string
  period_label: string | null
  target_value: number
  stretch_value: number | null
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
  targets: PaginatedData<TargetRow>
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
const perPage = ref(Number(props.filters.per_page) || props.targets.per_page)

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
  { key: 'target_value', label: 'Target', align: 'right' as const },
  { key: 'stretch_value', label: 'Stretch', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('performance.targets.index'), {
    kpi_id: filters.value.kpi_id,
    period_id: filters.value.period_id,
    subject_type: filters.value.subject_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: TargetRow | Record<string, unknown>) => {
  const row = item as TargetRow
  confirm({
    title: `Delete this target?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.targets.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Targets" description="Assign a KPI + target value to a subject and period.">
      <template #actions>
        <PrimaryButton :href="route('performance.targets.create')">Add target</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="targets" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="targets.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="performance.targets"
        :filter-fields="filterFields"
        export-filename="performance-targets"
        :total="targets.total"
        :from="targets.from"
        :to="targets.to"
        :links="targets.links"
        empty-title="No targets yet"
        empty-description="Assign a KPI target to a subject and period."
      >
        <template #cell-target_value="{ item }">{{ formatNumber((item as TargetRow).target_value) }} {{ (item as TargetRow).kpi_unit }}</template>
        <template #cell-stretch_value="{ item }">
          <span v-if="(item as TargetRow).stretch_value !== null">{{ formatNumber((item as TargetRow).stretch_value as number) }}</span>
          <span v-else class="text-ink-600">—</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.targets.edit', item.id)"
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
