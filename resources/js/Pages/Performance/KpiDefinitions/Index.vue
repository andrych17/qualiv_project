<!-- ponytail: KPI Definition listing (§3C) -->
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

interface KpiRow {
  id: number
  name: string
  unit: string
  direction: 'higher_is_better' | 'lower_is_better'
  perspective_name: string | null
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
  kpis: PaginatedData<KpiRow>
  filters: { search?: string; perspective_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  perspectives: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ perspective_id: props.filters.perspective_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.kpis.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'perspective_id', label: 'Perspective', type: 'select', options: props.perspectives.map((p) => ({ label: p.name, value: String(p.id) })) },
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
  { key: 'name', label: 'Name', sortable: true },
  { key: 'unit', label: 'Unit' },
  { key: 'direction', label: 'Direction' },
  { key: 'perspective_name', label: 'Perspective' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('performance.kpiDefinitions.index'), {
    search: search.value,
    perspective_id: filters.value.perspective_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: KpiRow | Record<string, unknown>) => {
  const row = item as KpiRow
  confirm({
    title: `Delete KPI ${row.name}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.kpiDefinitions.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected KPI(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('performance.kpiDefinitions.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="KPI Definitions" description="Tenant-defined metric library — assign to any subject via Targets.">
      <template #actions>
        <PrimaryButton :href="route('performance.kpiDefinitions.create')">Add KPI</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="kpiDefinitions" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="kpis.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="performance.kpiDefinitions"
        search-placeholder="Search name…"
        :filter-fields="filterFields"
        export-filename="performance-kpi-definitions"
        :total="kpis.total"
        :from="kpis.from"
        :to="kpis.to"
        :links="kpis.links"
        empty-title="No KPIs yet"
        empty-description="Add your first KPI definition."
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
        <template #cell-direction="{ item }">
          <span class="text-xs text-ink-600">{{ (item as KpiRow).direction === 'higher_is_better' ? 'Higher is better' : 'Lower is better' }}</span>
        </template>
        <template #cell-perspective_name="{ item }">
          <span>{{ (item as KpiRow).perspective_name ?? '—' }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as KpiRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.kpiDefinitions.edit', item.id)"
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
