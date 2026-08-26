<!-- ponytail: Employee Master Index — table listing with filters and bulk deletion. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

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

const props = defineProps<{
  employees: {
    data: EmployeeRow[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    total: number
  }
  filters: {
    search?: string
    employment_status?: string
    position_id?: string
    org_unit_id?: string
  }
  positions: Array<{ id: number; job?: { title: string }; org_unit?: { name: string } }>
  orgUnits: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search || '')
const status = ref(props.filters.employment_status || '')
const selectedOrgUnit = ref(props.filters.org_unit_id || '')

const applyFilters = () => {
  router.get(
    route('hcm.employees.index'),
    {
      search: search.value || undefined,
      employment_status: status.value || undefined,
      org_unit_id: selectedOrgUnit.value || undefined,
    },
    { preserveState: true }
  )
}

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

const statusVariant = (st: string) => {
  switch (st) {
    case 'active':
      return 'success'
    case 'on_leave':
      return 'info'
    case 'suspended':
      return 'warning'
    case 'terminated':
      return 'neutral'
    default:
      return 'neutral'
  }
}
</script>

<template>
  <AppLayout title="Employee Directory">
    <PageHeader title="Employees" subtitle="Manage internal workforce records and assignments.">
      <template #actions>
        <Link :href="route('hcm.employees.create')">
          <PrimaryButton>+ New Hire</PrimaryButton>
        </Link>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <HcmSubNav active="employees" />

      <!-- Filters -->
      <Panel>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label class="block text-xs font-medium text-ink-700">Search</label>
            <input
              v-model="search"
              type="text"
              placeholder="Name, Emp No, NIK..."
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              @keyup.enter="applyFilters"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Employment Status</label>
            <select
              v-model="status"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              @change="applyFilters"
            >
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="on_leave">On Leave</option>
              <option value="suspended">Suspended</option>
              <option value="terminated">Terminated</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Department / Org Unit</label>
            <select
              v-model="selectedOrgUnit"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              @change="applyFilters"
            >
              <option value="">All Org Units</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
            </select>
          </div>
        </div>
      </Panel>

      <!-- Table List -->
      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">Position / Dept</th>
                <th class="px-4 py-3">Contract</th>
                <th class="px-4 py-3">Hire Date</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-if="employees.data.length === 0">
                <td colspan="6" class="p-4 text-center text-sm text-ink-500">
                  No employees found.
                </td>
              </tr>
              <tr
                v-for="emp in employees.data"
                :key="emp.id"
                class="hover:bg-surface-raised transition"
              >
                <td class="px-4 py-3">
                  <Link :href="route('hcm.employees.show', emp.id)" class="font-medium text-ink-900 hover:text-accent">
                    {{ emp.full_name }}
                  </Link>
                  <div class="text-xs text-ink-500">{{ emp.employee_no }}</div>
                </td>
                <td class="px-4 py-3">
                  <div class="text-ink-900">{{ emp.position?.job?.title ?? '-' }}</div>
                  <div class="text-xs text-ink-500">{{ emp.position?.org_unit?.name ?? '-' }}</div>
                </td>
                <td class="px-4 py-3">
                  <span v-if="emp.current_contract" class="text-xs font-medium text-ink-700">
                    {{ emp.current_contract.contract_type }}
                  </span>
                  <span v-else class="text-xs text-ink-400">None</span>
                </td>
                <td class="px-4 py-3 text-ink-700">{{ emp.hire_date }}</td>
                <td class="px-4 py-3">
                  <StatusBadge :status="emp.employment_status" :variant="statusVariant(emp.employment_status)">
                    {{ emp.employment_status.replace('_', ' ') }}
                  </StatusBadge>
                </td>
                <td class="px-4 py-3 text-right space-x-2">
                  <Link :href="route('hcm.employees.show', emp.id)" class="text-xs font-medium text-ink-600 hover:text-ink-900">
                    View
                  </Link>
                  <Link :href="route('hcm.employees.edit', emp.id)" class="text-xs font-medium text-accent hover:underline">
                    Edit
                  </Link>
                  <button
                    type="button"
                    class="text-xs font-medium text-danger hover:underline"
                    @click="deleteEmployee(emp)"
                  >
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
