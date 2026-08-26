<!-- ponytail: Employee Master Detail (§3B) — tabbed profile with contract, position, leave, and attendance history. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface Employee {
  id: number
  employee_no: string
  full_name: string
  date_of_birth?: string
  gender?: string
  nik?: string
  npwp?: string
  bpjs_kesehatan_no?: string
  bpjs_ketenagakerjaan_no?: string
  address?: string
  marital_status?: string
  dependents_count: number
  religion?: string
  hire_date: string
  employment_status: string
  position?: {
    job?: { title: string }
    org_unit?: { name: string }
    reports_to?: { job?: { title: string } }
  }
  contracts: Array<{
    id: number
    contract_type: string
    start_date: string
    end_date?: string
    base_salary: string
    work_location?: string
    probation_end_date?: string
    status: string
  }>
  position_histories: Array<{
    id: number
    position?: { job?: { title: string }; org_unit?: { name: string } }
    effective_from: string
    effective_to?: string
    changed_by?: { name: string }
  }>
  attendance_logs: Array<{
    id: number
    clock_in_at: string
    clock_out_at?: string
    exception_flag: string
  }>
  leave_balances: Array<{
    id: number
    leave_type?: { name: string }
    period_year: number
    entitled_days: string
    used_days: string
    carried_over_days: string
  }>
  leave_requests: Array<{
    id: number
    leave_type?: { name: string }
    start_date: string
    end_date: string
    reason?: string
    status: string
  }>
}

const props = defineProps<{
  employee: Employee
  leaveBalances: Array<any>
}>()

const activeTab = ref<'overview' | 'contracts' | 'positions' | 'leave' | 'attendance'>('overview')

const terminateForm = useForm({
  termination_date: new Date().toISOString().split('T')[0],
  termination_reason: '',
})

const showTerminateModal = ref(false)
const submitTerminate = () => {
  terminateForm.post(route('hcm.employees.terminate', props.employee.id), {
    onSuccess: () => {
      showTerminateModal.value = false
    },
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
  <AppLayout :title="employee.full_name">
    <PageHeader :title="employee.full_name" :subtitle="`${employee.employee_no} • ${employee.position?.job?.title ?? 'No Position'}`">
      <template #actions>
        <div class="flex items-center space-x-2">
          <Link :href="route('hcm.employees.edit', employee.id)">
            <SecondaryButton>Edit Profile</SecondaryButton>
          </Link>
          <button
            v-if="employee.employment_status !== 'terminated'"
            type="button"
            class="rounded-md border border-danger/40 bg-danger/10 px-3 py-1.5 text-xs font-semibold text-danger hover:bg-danger/20 transition"
            @click="showTerminateModal = true"
          >
            Terminate Employment
          </button>
        </div>
      </template>
    </PageHeader>

    <div class="space-y-6">
      <!-- Tabs header -->
      <div class="flex items-center gap-2 border-b border-border" role="tablist">
        <button
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium transition"
          :class="activeTab === 'overview' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = 'overview'"
        >
          Overview
        </button>
        <button
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium transition"
          :class="activeTab === 'contracts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = 'contracts'"
        >
          Contracts ({{ employee.contracts.length }})
        </button>
        <button
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium transition"
          :class="activeTab === 'positions' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = 'positions'"
        >
          Position History ({{ employee.position_histories.length }})
        </button>
        <button
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium transition"
          :class="activeTab === 'leave' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = 'leave'"
        >
          Leave
        </button>
        <button
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium transition"
          :class="activeTab === 'attendance' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = 'attendance'"
        >
          Attendance Logs
        </button>
      </div>

      <!-- Tab: Overview -->
      <div v-if="activeTab === 'overview'" class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <Panel title="Personal Identity">
          <dl class="divide-y divide-border text-sm">
            <div class="flex justify-between py-2"><dt class="text-ink-500">Employee No</dt><dd class="font-medium text-ink-900">{{ employee.employee_no }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Full Name</dt><dd class="font-medium text-ink-900">{{ employee.full_name }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">NIK (KTP)</dt><dd class="font-medium text-ink-900">{{ employee.nik || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">NPWP</dt><dd class="font-medium text-ink-900">{{ employee.npwp || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Date of Birth</dt><dd class="font-medium text-ink-900">{{ employee.date_of_birth || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Gender</dt><dd class="font-medium text-ink-900 capitalize">{{ employee.gender || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Marital Status</dt><dd class="font-medium text-ink-900 capitalize">{{ employee.marital_status || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Dependents</dt><dd class="font-medium text-ink-900">{{ employee.dependents_count }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Religion</dt><dd class="font-medium text-ink-900">{{ employee.religion || '-' }}</dd></div>
          </dl>
        </Panel>

        <Panel title="Employment & Statutory">
          <dl class="divide-y divide-border text-sm">
            <div class="flex justify-between py-2">
              <dt class="text-ink-500">Status</dt>
              <dd>
                <StatusBadge :status="employee.employment_status" :variant="statusVariant(employee.employment_status)">
                  {{ employee.employment_status }}
                </StatusBadge>
              </dd>
            </div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Hire Date</dt><dd class="font-medium text-ink-900">{{ employee.hire_date }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Department</dt><dd class="font-medium text-ink-900">{{ employee.position?.org_unit?.name ?? '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Position</dt><dd class="font-medium text-ink-900">{{ employee.position?.job?.title ?? '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">Direct Manager</dt><dd class="font-medium text-ink-900">{{ employee.position?.reports_to?.job?.title ?? '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">BPJS Kesehatan</dt><dd class="font-medium text-ink-900">{{ employee.bpjs_kesehatan_no || '-' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-ink-500">BPJS Ketenagakerjaan</dt><dd class="font-medium text-ink-900">{{ employee.bpjs_ketenagakerjaan_no || '-' }}</dd></div>
          </dl>
        </Panel>
      </div>

      <!-- Tab: Contracts -->
      <div v-if="activeTab === 'contracts'">
        <Panel title="Employment Contracts">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border text-left text-sm">
              <thead class="bg-surface-sunken text-xs font-medium text-ink-500 uppercase">
                <tr>
                  <th class="px-4 py-2">Type</th>
                  <th class="px-4 py-2">Start Date</th>
                  <th class="px-4 py-2">End Date</th>
                  <th class="px-4 py-2">Base Salary</th>
                  <th class="px-4 py-2">Location</th>
                  <th class="px-4 py-2">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="c in employee.contracts" :key="c.id">
                  <td class="px-4 py-2 font-medium">{{ c.contract_type }}</td>
                  <td class="px-4 py-2">{{ c.start_date }}</td>
                  <td class="px-4 py-2">{{ c.end_date || 'Permanent' }}</td>
                  <td class="px-4 py-2">Rp {{ Number(c.base_salary).toLocaleString('id-ID') }}</td>
                  <td class="px-4 py-2">{{ c.work_location || '-' }}</td>
                  <td class="px-4 py-2">
                    <StatusBadge :status="c.status" :variant="c.status === 'active' ? 'success' : 'neutral'">
                      {{ c.status }}
                    </StatusBadge>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      <!-- Tab: Position History -->
      <div v-if="activeTab === 'positions'">
        <Panel title="Position & Role Audit History">
          <div class="divide-y divide-border text-sm">
            <div v-for="h in employee.position_histories" :key="h.id" class="p-3 flex justify-between">
              <div>
                <div class="font-medium text-ink-900">{{ h.position?.job?.title }} ({{ h.position?.org_unit?.name }})</div>
                <div class="text-xs text-ink-500">Effective: {{ h.effective_from }} {{ h.effective_to ? 'to ' + h.effective_to : '(Current)' }}</div>
              </div>
              <div v-if="h.changed_by" class="text-xs text-ink-400">Changed by {{ h.changed_by.name }}</div>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Tab: Leave -->
      <div v-if="activeTab === 'leave'" class="space-y-6">
        <Panel title="Leave Balances">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="b in leaveBalances" :key="b.id" class="rounded-lg border border-border p-3">
              <div class="text-xs font-semibold text-ink-600">{{ b.leave_type?.name }} ({{ b.period_year }})</div>
              <div class="mt-2 text-xl font-bold text-ink-900">{{ (Number(b.entitled_days) + Number(b.carried_over_days)) - Number(b.used_days) }} days</div>
              <div class="text-xs text-ink-500">Used: {{ b.used_days }} / Entitled: {{ b.entitled_days }}</div>
            </div>
          </div>
        </Panel>

        <Panel title="Leave Requests">
          <div class="divide-y divide-border text-sm">
            <div v-if="employee.leave_requests.length === 0" class="p-4 text-center text-ink-500">No leave requests found.</div>
            <div v-for="r in employee.leave_requests" :key="r.id" class="p-3 flex justify-between items-center">
              <div>
                <div class="font-medium text-ink-900">{{ r.leave_type?.name }}</div>
                <div class="text-xs text-ink-500">{{ r.start_date }} to {{ r.end_date }} &bull; {{ r.reason || 'No reason' }}</div>
              </div>
              <StatusBadge :status="r.status" :variant="r.status === 'approved' ? 'success' : (r.status === 'pending' ? 'warning' : 'danger')">
                {{ r.status }}
              </StatusBadge>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Tab: Attendance -->
      <div v-if="activeTab === 'attendance'">
        <Panel title="Recent Attendance Logs">
          <div class="divide-y divide-border text-sm">
            <div v-if="employee.attendance_logs.length === 0" class="p-4 text-center text-ink-500">No attendance logs found.</div>
            <div v-for="log in employee.attendance_logs" :key="log.id" class="p-3 flex justify-between items-center">
              <div>
                <div class="font-medium text-ink-900">In: {{ log.clock_in_at }}</div>
                <div class="text-xs text-ink-500">Out: {{ log.clock_out_at || 'Still clocked in' }}</div>
              </div>
              <StatusBadge :status="log.exception_flag" :variant="log.exception_flag === 'on_time' ? 'success' : 'warning'">
                {{ log.exception_flag }}
              </StatusBadge>
            </div>
          </div>
        </Panel>
      </div>
    </div>

    <!-- Terminate Modal -->
    <div v-if="showTerminateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/50 p-4">
      <div class="w-full max-w-md rounded-lg bg-surface p-6 shadow-xl border border-border">
        <h3 class="text-lg font-bold text-ink-900">Terminate Employment</h3>
        <p class="mt-1 text-sm text-ink-600">Mark employee as terminated and close active contract.</p>

        <form @submit.prevent="submitTerminate" class="mt-4 space-y-4">
          <div>
            <label class="block text-xs font-medium text-ink-700">Termination Date *</label>
            <input
              v-model="terminateForm.termination_date"
              type="date"
              required
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-700">Reason</label>
            <input
              v-model="terminateForm.termination_reason"
              type="text"
              placeholder="e.g. Resignation, End of Contract, Severance"
              class="mt-1 block w-full rounded-md border-border bg-surface text-sm text-ink-900 shadow-sm focus:border-accent focus:ring-accent"
            />
          </div>

          <div class="flex justify-end space-x-3 pt-2">
            <SecondaryButton type="button" @click="showTerminateModal = false">Cancel</SecondaryButton>
            <PrimaryButton class="!bg-danger" :disabled="terminateForm.processing">Confirm Termination</PrimaryButton>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
