<!-- ponytail: Payroll Runs Index — batch execution list and filterable table. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface Run {
  id: number
  uuid: string
  run_number: string
  period_start: string
  period_end: string
  pay_date?: string
  run_type: string
  status: string
  total_gross: string
  total_net: string
  total_tax_pph21: string
  total_bpjs_employer: string
  total_bpjs_employee: string
  payroll_group?: { name: string }
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
  runs: PaginatedData<Run>
  filters: {
    search?: string
    status?: string
    run_type?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  run_type: props.filters.run_type ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.runs.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Calculated', value: 'calculated' },
      { label: 'Approved', value: 'approved' },
      { label: 'Paid', value: 'paid' },
      { label: 'Locked', value: 'locked' },
    ],
  },
  {
    key: 'run_type',
    label: 'Run Type',
    type: 'select',
    options: [
      { label: 'Regular', value: 'regular' },
      { label: 'Off Cycle', value: 'off_cycle' },
      { label: 'THR (Holiday Bonus)', value: 'thr' },
      { label: 'Bonus', value: 'bonus' },
      { label: 'Severance', value: 'severance' },
    ],
  },
]

const columns = [
  { key: 'run_number', label: 'Run Number', sortable: true },
  { key: 'run_type', label: 'Type', sortable: true },
  { key: 'period', label: 'Period' },
  { key: 'pay_date', label: 'Pay Date', sortable: true },
  { key: 'total_net', label: 'Total Net Pay', sortable: true, align: 'right' as const },
  { key: 'total_tax_pph21', label: 'PPh 21', align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('payroll.runs.index'),
    {
      search: search.value || undefined,
      status: filters.value.status || undefined,
      run_type: filters.value.run_type || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Payroll Runs">
    <PageHeader title="Payroll Runs" subtitle="Manage and execute monthly and off-cycle payroll batches.">
      <template #actions>
        <PrimaryButton :href="route('payroll.runs.create')">+ New Payroll Run</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PayrollSubNav active="runs" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="runs.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="payroll.runs"
        search-placeholder="Search run #, payroll group…"
        :filter-fields="filterFields"
        export-filename="payroll-runs"
        status-rail-key="status"
        :total="runs.total"
        :from="runs.from"
        :to="runs.to"
        :links="runs.links"
        empty-title="No payroll runs found"
        empty-description="Initiate your first payroll calculation batch."
      >
        <template #cell-run_number="{ item }">
          <div>
            <Link
              :href="route('payroll.runs.show', item.id)"
              class="font-mono font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as Run).run_number }}
            </Link>
            <span v-if="(item as Run).payroll_group" class="block text-[11px] text-ink-400">
              {{ (item as Run).payroll_group?.name }}
            </span>
          </div>
        </template>

        <template #cell-run_type="{ item }">
          <span class="text-xs capitalize font-medium text-ink-800">{{ (item as Run).run_type.replace('_', ' ') }}</span>
        </template>

        <template #cell-period="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ formatDate((item as Run).period_start) }} - {{ formatDate((item as Run).period_end) }}
          </span>
        </template>

        <template #cell-pay_date="{ item }">
          <span v-if="(item as Run).pay_date" class="font-mono text-xs text-ink-700">
            {{ formatDate((item as Run).pay_date!) }}
          </span>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-total_net="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency(Number((item as Run).total_net)) }}
          </span>
        </template>

        <template #cell-total_tax_pph21="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ formatCurrency(Number((item as Run).total_tax_pph21)) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as Run).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <Link
              :href="route('payroll.runs.show', item.id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              View & Calculate &rarr;
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
