<!-- ponytail: Employee Payroll Profiles Index — PTKP, BPJS, NPWP, and Salary Structure assignment. -->
<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import Modal from '@/Components/Modal.vue'

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

const props = defineProps<{
  employees: { data: Employee[]; total: number }
  payrollGroups: Array<{ id: number; name: string }>
  salaryStructures: Array<{ id: number; name: string }>
  ptkpStatuses: Array<{ code: string; description: string; ter_category: string }>
  jkkCategories: Array<{ id: number; name: string }>
}>()

const form = useForm({
  employee_id: null as number | null,
  ptkp_status_code: 'TK/0',
  npwp_number: '',
  has_npwp: true,
  bpjs_kesehatan_no: '',
  bpjs_ketenagakerjaan_no: '',
  payroll_group_id: '' as string | number,
  salary_structure_id: '' as string | number,
  jkk_risk_category_id: '' as string | number,
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
  form.payroll_group_id = emp.payroll_profile?.payroll_group_id || ''
  form.salary_structure_id = emp.payroll_profile?.salary_structure_id || ''
  form.jkk_risk_category_id = emp.payroll_profile?.jkk_risk_category_id || ''
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
</script>

<template>
  <AppLayout title="Employee Payroll Profiles">
    <PageHeader title="Employee Profiles" subtitle="Configure PTKP tax codes, NPWP, BPJS numbers, and payroll groups." />

    <div class="space-y-6">
      <PayrollSubNav active="profiles" />

      <Panel>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">PTKP Code</th>
                <th class="px-4 py-3">NPWP</th>
                <th class="px-4 py-3">Payroll Group</th>
                <th class="px-4 py-3">Structure</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="employees.data.length === 0">
                <td colspan="6" class="p-4 text-center text-ink-500">No active employees found.</td>
              </tr>
              <tr v-for="emp in employees.data" :key="emp.id" class="hover:bg-surface-raised transition">
                <td class="px-4 py-3">
                  <div class="font-medium text-ink-900">{{ emp.full_name }}</div>
                  <div class="text-xs text-ink-500">{{ emp.employee_no }} &bull; {{ emp.position?.job?.title ?? '-' }}</div>
                </td>
                <td class="px-4 py-3 font-semibold text-ink-800">
                  {{ emp.payroll_profile?.ptkp_status_code ?? 'TK/0' }}
                </td>
                <td class="px-4 py-3 text-xs text-ink-700">
                  {{ emp.payroll_profile?.npwp_number || emp.npwp || 'No NPWP (120% Tax)' }}
                </td>
                <td class="px-4 py-3 text-ink-600">
                  {{ emp.payroll_profile?.payroll_group?.name ?? '—' }}
                </td>
                <td class="px-4 py-3 text-ink-600">
                  {{ emp.payroll_profile?.salary_structure?.name ?? '—' }}
                </td>
                <td class="px-4 py-3 text-right">
                  <button
                    type="button"
                    class="text-xs font-medium text-accent hover:underline"
                    @click="openEdit(emp)"
                  >
                    Edit Profile
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Edit Profile Modal -->
    <Modal :show="showModal" max-width="lg" @close="showModal = false">
      <div class="p-6 bg-white rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Payroll Profile: {{ selectedEmployee?.full_name }}</h3>
        <form @submit.prevent="submit" class="mt-4 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-ink-700">PTKP Status Code *</label>
              <select
                v-model="form.ptkp_status_code"
                required
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              >
                <option v-for="p in ptkpStatuses" :key="p.code" :value="p.code">
                  {{ p.code }} (TER {{ p.ter_category }})
                </option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">Payroll Group</label>
              <select
                v-model="form.payroll_group_id"
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              >
                <option value="">-- None --</option>
                <option v-for="g in payrollGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">NPWP Number</label>
            <input
              v-model="form.npwp_number"
              type="text"
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-ink-700">BPJS Kesehatan No</label>
              <input
                v-model="form.bpjs_kesehatan_no"
                type="text"
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-ink-700">BPJS Ketenagakerjaan No</label>
              <input
                v-model="form.bpjs_ketenagakerjaan_no"
                type="text"
                class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-ink-700">Salary Structure</label>
            <select
              v-model="form.salary_structure_id"
              class="mt-1 block w-full rounded-md border-border bg-white text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            >
              <option value="">-- None --</option>
              <option v-for="s in salaryStructures" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>

          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton :disabled="form.processing">Save Profile</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
