<!-- ponytail: Budget list (§3B) — status ladder draft → submitted → approved → locked. -->
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

interface BudgetRow {
  id: number
  name: string
  subject_label: string
  fiscal_label: string
  status: string
  version_no: number
  lines_count: number
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
  budgets: PaginatedData<BudgetRow>
  filters: { status?: string; fiscal_year?: string; subject_type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({
  status: props.filters.status ?? '',
  fiscal_year: props.filters.fiscal_year ?? '',
  subject_type: props.filters.subject_type ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.budgets.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Submitted', value: 'submitted' },
      { label: 'Approved', value: 'approved' },
      { label: 'Locked', value: 'locked' },
    ],
  },
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
  { key: 'name', label: 'Name', sortable: true },
  { key: 'subject_label', label: 'Subject' },
  { key: 'fiscal_label', label: 'Fiscal period', sortable: true, sortKey: 'fiscal_year' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'version_no', label: 'Version', align: 'right' as const },
  { key: 'lines_count', label: 'Lines', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('performance.budgets.index'), {
    status: filters.value.status,
    fiscal_year: filters.value.fiscal_year,
    subject_type: filters.value.subject_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: BudgetRow | Record<string, unknown>) => {
  const row = item as BudgetRow
  confirm({
    title: `Delete "${row.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.budgets.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Budgets" description="Plan spend per category and period, tracked against actuals.">
      <template #actions>
        <PrimaryButton :href="route('performance.budgets.create')">New budget</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="budgets" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="budgets.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="performance.budgets"
        :filter-fields="filterFields"
        export-filename="performance-budgets"
        :total="budgets.total"
        :from="budgets.from"
        :to="budgets.to"
        :links="budgets.links"
        empty-title="No budgets yet"
        empty-description="Create a budget to start planning spend by category and period."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as BudgetRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.budgets.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as BudgetRow).status === 'draft' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as BudgetRow).status === 'draft'"
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
