<!-- ponytail: Employee Master Index — table listing with filters and bulk deletion. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatDate } from '@/Utils/formatters'

interface EmployeeRow {
  id: number
  employee_no: string
  full_name: string
  nik?: string
  hire_date: string
  employment_status: string
  position?: {
    job?: { title: string }
    org_unit?: { name: string }
  }
  current_contract?: {
    contract_type: string
    base_salary: string
  }
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
  employees: PaginatedData<EmployeeRow>
  filters: {
    search?: string
    employment_status?: string
    position_id?: string
    org_unit_id?: string
    sort?: string
    direction?: string
    per_page?: string
  }
  positions: Array<{ id: number; job?: { title: string }; org_unit?: { name: string } }>
  orgUnits: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  employment_status: props.filters.employment_status ?? '',
  org_unit_id: props.filters.org_unit_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.employees.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'employment_status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'On Leave', value: 'on_leave' },
      { label: 'Suspended', value: 'suspended' },
      { label: 'Terminated', value: 'terminated' },
    ],
  },
  {
    key: 'org_unit_id',
    label: 'Org Unit',
    type: 'select',
    options: props.orgUnits.map((u) => ({ label: u.name, value: String(u.id) })),
  },
]

const columns = [
  { key: 'employee_no', label: 'Employee #', sortable: true },
  { key: 'full_name', label: 'Full Name', sortable: true },
  { key: 'position', label: 'Role & Org Unit' },
  { key: 'contract', label: 'Contract' },
  { key: 'hire_date', label: 'Hire Date', sortable: true },
  { key: 'employment_status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const { confirm } = useConfirm()

const deleteEmployee = (emp: EmployeeRow) => {
  confirm({
    title: `Delete employee "${emp.full_name}" (${emp.employee_no})?`,
    description: 'This will permanently remove the employee record.',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('hcm.employees.destroy', emp.id)),
  })
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('hcm.employees.index'),
    {
      search: search.value || undefined,
      employment_status: filters.value.employment_status || undefined,
      org_unit_id: filters.value.org_unit_id || undefined,
      sort: sort.value?.key,
      direction: sort.value?.direction,
      per_page: perPage.value,
    },
    { preserveState: true, replace: true }
  )
}, 400))
</script>

<template>
  <AppLayout title="Employee Directory">
    <PageHeader title="Employees" subtitle="Manage internal workforce records and assignments.">
      <template #actions>
        <PrimaryButton :href="route('hcm.employees.create')">+ New Hire</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <HcmSubNav active="employees" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="employees.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="hcm.employees"
        search-placeholder="Search name, employee #, NIK…"
        :filter-fields="filterFields"
        export-filename="hcm-employees"
        status-rail-key="employment_status"
        :total="employees.total"
        :from="employees.from"
        :to="employees.to"
        :links="employees.links"
        empty-title="No employees found"
        empty-description="Register your first employee record or onboard a new hire."
      >
        <template #cell-employee_no="{ item }">
          <Link
            :href="route('hcm.employees.show', item.id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as EmployeeRow).employee_no }}
          </Link>
        </template>

        <template #cell-full_name="{ item }">
          <div>
            <Link
              :href="route('hcm.employees.show', item.id)"
              class="font-semibold text-ink-900 hover:text-accent"
            >
              {{ (item as EmployeeRow).full_name }}
            </Link>
            <span v-if="(item as EmployeeRow).nik" class="block font-mono text-[11px] text-ink-400">
              NIK: {{ (item as EmployeeRow).nik }}
            </span>
          </div>
        </template>

        <template #cell-position="{ item }">
          <div class="text-xs text-ink-700">
            <span class="font-medium text-ink-900">{{ (item as EmployeeRow).position?.job?.title ?? '-' }}</span>
            <span class="block text-ink-500">{{ (item as EmployeeRow).position?.org_unit?.name ?? '' }}</span>
          </div>
        </template>

        <template #cell-contract="{ item }">
          <span v-if="(item as EmployeeRow).current_contract" class="text-xs capitalize text-ink-700">
            {{ (item as EmployeeRow).current_contract?.contract_type }}
          </span>
          <span v-else class="text-xs text-ink-400">No active contract</span>
        </template>

        <template #cell-hire_date="{ item }">
          <span class="font-mono text-xs text-ink-600">
            {{ formatDate((item as EmployeeRow).hire_date) }}
          </span>
        </template>

        <template #cell-employment_status="{ item }">
          <StatusBadge :status="(item as EmployeeRow).employment_status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('hcm.employees.edit', item.id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              @click="deleteEmployee(item as EmployeeRow)"
              class="text-xs font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
