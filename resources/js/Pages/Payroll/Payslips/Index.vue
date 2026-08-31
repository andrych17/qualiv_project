<!-- ponytail: Payslips Index — HR/payroll-admin browse across every calculated run's payslip
     lines, linking into the existing per-payslip PayslipController::show() detail page. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface PayslipRow {
  id: number
  gross_total: string
  net_total: string
  take_home_pay: string
  employee?: { id: number; employee_no: string; full_name: string }
  payroll_run?: { id: number; run_number: string; period_start: string; period_end: string }
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
  payslips: PaginatedData<PayslipRow>
  filters: { search?: string; payroll_run_id?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.payslips.per_page)

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'run', label: 'Payroll Run' },
  { key: 'gross_total', label: 'Gross', align: 'right' as const, sortable: true },
  { key: 'net_total', label: 'Net', align: 'right' as const, sortable: true },
  { key: 'take_home_pay', label: 'Take-Home Pay', align: 'right' as const, sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('payroll.payslips.index'),
    {
      search: search.value || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Payslips">
    <PageHeader title="Payslips" subtitle="Every payslip line across calculated payroll runs." />

    <div class="mt-4">
      <PayrollSubNav active="payslips" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="payslips.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        sticky-header
        storage-key="payroll.payslips"
        search-placeholder="Search employee name or number…"
        export-filename="payroll-payslips"
        :total="payslips.total"
        :from="payslips.from"
        :to="payslips.to"
        :links="payslips.links"
        empty-title="No payslips yet"
        empty-description="Payslips appear here once a payroll run has been calculated."
      >
        <template #cell-employee="{ item }">
          <div v-if="(item as PayslipRow).employee">
            <Link
              :href="route('payroll.payslips.show', item.id)"
              class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as PayslipRow).employee!.full_name }}
            </Link>
            <span class="block text-[11px] text-ink-400">{{ (item as PayslipRow).employee!.employee_no }}</span>
          </div>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-run="{ item }">
          <div v-if="(item as PayslipRow).payroll_run">
            <span class="block font-mono text-xs font-medium text-ink-800">{{ (item as PayslipRow).payroll_run!.run_number }}</span>
            <span class="block text-[11px] text-ink-500">
              {{ formatDate((item as PayslipRow).payroll_run!.period_start) }} - {{ formatDate((item as PayslipRow).payroll_run!.period_end) }}
            </span>
          </div>
          <span v-else class="text-xs text-ink-400">—</span>
        </template>

        <template #cell-gross_total="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatCurrency(Number((item as PayslipRow).gross_total)) }}</span>
        </template>

        <template #cell-net_total="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatCurrency(Number((item as PayslipRow).net_total)) }}</span>
        </template>

        <template #cell-take_home_pay="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(Number((item as PayslipRow).take_home_pay)) }}</span>
        </template>

        <template #cell-actions="{ item }">
          <Link
            :href="route('payroll.payslips.show', item.id)"
            class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            View payslip &rarr;
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
