<!-- ponytail: Employee Payroll Profiles Index — PTKP, BPJS, NPWP, and Salary Structure assignment. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import Modal from '@/Components/Modal.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { debounce } from '@/Composables/debounce'

interface Employee {
  id: number
  employee_no: string
  full_name: string
  npwp?: string
  position?: { job?: { title: string }; org_unit?: { name: string } }
  current_contract?: { base_salary: string }
  payroll_profile?: {
    ptkp_status_code: string
    npwp_number?: string
    has_npwp: boolean
    bpjs_kesehatan_no?: string
    bpjs_ketenagakerjaan_no?: string
    payroll_group_id?: number
    salary_structure_id?: number
    jkk_risk_category_id?: number
    is_tax_borne_by_company: boolean
    proration_rule: string
    payroll_group?: { name: string }
    salary_structure?: { name: string }
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
  employees: PaginatedData<Employee>
  payrollGroups: Array<{ id: number; name: string }>
  salaryStructures: Array<{ id: number; name: string }>
  ptkpStatuses: Array<{ code: string; description: string; ter_category: string }>
  jkkCategories: Array<{ id: number; name: string }>
  filters?: {
    search?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters?.search ?? '')
const sort = ref<SortState>(
  props.filters?.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters?.per_page) || props.employees.per_page)

const columns = [
  { key: 'employee', label: 'Employee' },
  { key: 'ptkp_code', label: 'PTKP Code' },
  { key: 'npwp', label: 'NPWP' },
  { key: 'payroll_group', label: 'Payroll Group' },
  { key: 'structure', label: 'Structure' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const form = useForm({
  employee_id: null as number | null,
  ptkp_status_code: 'TK/0',
  npwp_number: '',
  has_npwp: true,
  bpjs_kesehatan_no: '',
  bpjs_ketenagakerjaan_no: '',
  payroll_group_id: null as number | null,
  salary_structure_id: null as number | null,
  jkk_risk_category_id: null as number | null,
  is_tax_borne_by_company: false,
  proration_rule: 'work_days',
})

const showModal = ref(false)
const selectedEmployee = ref<Employee | null>(null)

const openEdit = (emp: Employee) => {
  selectedEmployee.value = emp
  form.employee_id = emp.id
  form.ptkp_status_code = emp.payroll_profile?.ptkp_status_code || 'TK/0'
  form.npwp_number = emp.payroll_profile?.npwp_number || emp.npwp || ''
  form.has_npwp = emp.payroll_profile?.has_npwp ?? true
  form.bpjs_kesehatan_no = emp.payroll_profile?.bpjs_kesehatan_no || ''
  form.bpjs_ketenagakerjaan_no = emp.payroll_profile?.bpjs_ketenagakerjaan_no || ''
  form.payroll_group_id = emp.payroll_profile?.payroll_group_id ?? null
  form.salary_structure_id = emp.payroll_profile?.salary_structure_id ?? null
  form.jkk_risk_category_id = emp.payroll_profile?.jkk_risk_category_id ?? null
  form.is_tax_borne_by_company = emp.payroll_profile?.is_tax_borne_by_company || false
  form.proration_rule = emp.payroll_profile?.proration_rule || 'work_days'
  showModal.value = true
}

const submit = () => {
  if (!form.employee_id) return
  form.put(route('payroll.profiles.update', form.employee_id), {
    onSuccess: () => {
      showModal.value = false
    },
  })
}

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(
    route('payroll.profiles.index'),
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
  <AppLayout title="Employee Payroll Profiles">
    <PageHeader title="Employee Profiles" subtitle="Configure PTKP tax codes, NPWP, BPJS numbers, and payroll groups." />

    <div class="mt-4">
      <PayrollSubNav active="profiles" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="employees.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        sticky-header
        storage-key="payroll.profiles"
        search-placeholder="Search employee…"
        export-filename="payroll-employee-profiles"
        :total="employees.total"
        :from="employees.from"
        :to="employees.to"
        :links="employees.links"
        empty-title="No active employees found"
        empty-description="Onboard employees to configure their statutory payroll profiles."
      >
        <template #cell-employee="{ item }">
          <span class="font-semibold text-ink-900">{{ (item as Employee).full_name }}</span>
          <span class="block font-mono text-[11px] text-ink-400">
            {{ (item as Employee).employee_no }} &bull; <span class="font-sans">{{ (item as Employee).position?.job?.title ?? '-' }}</span>
          </span>
        </template>

        <template #cell-ptkp_code="{ item }">
          <span class="font-semibold text-xs text-ink-800">
            {{ (item as Employee).payroll_profile?.ptkp_status_code ?? 'TK/0' }}
          </span>
        </template>

        <template #cell-npwp="{ item }">
          <span class="font-mono text-xs text-ink-700">
            {{ (item as Employee).payroll_profile?.npwp_number || (item as Employee).npwp || 'No NPWP (120% Tax)' }}
          </span>
        </template>

        <template #cell-payroll_group="{ item }">
          <span class="text-xs text-ink-700">
            {{ (item as Employee).payroll_profile?.payroll_group?.name ?? '—' }}
          </span>
        </template>

        <template #cell-structure="{ item }">
          <span class="text-xs text-ink-700">
            {{ (item as Employee).payroll_profile?.salary_structure?.name ?? '—' }}
          </span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <button
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="openEdit(item as Employee)"
            >
              Edit Profile
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Edit Profile Modal -->
    <Modal :show="showModal" max-width="lg" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Payroll Profile: {{ selectedEmployee?.full_name }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <FormSelect
                label="PTKP Status Code"
                name="ptkp_status_code"
                v-model="form.ptkp_status_code"
                :options="ptkpStatuses.map(p => ({ label: `${p.code} (TER ${p.ter_category})`, value: p.code }))"
                required
              />
            </div>
            <div>
              <FormSelect
                label="Payroll Group"
                name="payroll_group_id"
                v-model="form.payroll_group_id"
                :options="payrollGroups.map(g => ({ label: g.name, value: g.id }))"
                placeholder="None"
              />
            </div>
          </div>

          <div>
            <FormInput
              label="NPWP Number"
              name="npwp_number"
              v-model="form.npwp_number"
              placeholder="e.g. 01.234.567.8-901.000"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <FormInput
                label="BPJS Kesehatan No"
                name="bpjs_kesehatan_no"
                v-model="form.bpjs_kesehatan_no"
              />
            </div>
            <div>
              <FormInput
                label="BPJS Ketenagakerjaan No"
                name="bpjs_ketenagakerjaan_no"
                v-model="form.bpjs_ketenagakerjaan_no"
              />
            </div>
          </div>

          <div>
            <FormSelect
              label="Salary Structure"
              name="salary_structure_id"
              v-model="form.salary_structure_id"
              :options="salaryStructures.map(s => ({ label: s.name, value: s.id }))"
              placeholder="None"
            />
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Profile</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
