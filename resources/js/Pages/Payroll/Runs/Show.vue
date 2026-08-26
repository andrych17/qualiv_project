<!-- ponytail: Payroll Run Detail — execution status, recalculation, approval, and itemized employee payslip table. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface RunLine {
  id: number
  employee_id: number
  basic_salary: string
  gross_total: string
  bpjs_kesehatan_employee: string
  bpjs_tk_employee: string
  pph21_amount: string
  other_deductions: string
  net_total: string
  take_home_pay: string
  ptkp_status_code: string
  ter_category?: string
  ter_rate_percentage?: string
  employee: {
    id: number
    employee_no: string
    full_name: string
    position?: {
      job?: { title: string }
      org_unit?: { name: string }
    }
  }
}

interface Run {
  id: number
  uuid: string
  run_number: string
  period_start: string
  period_end: string
  pay_date: string
  run_type: string
  status: string
  total_gross: string
  total_deductions: string
  total_net: string
  total_tax_pph21: string
  total_bpjs_employer: string
  total_bpjs_employee: string
  is_locked: boolean
  payroll_group?: { name: string }
  lines: RunLine[]
}

const props = defineProps<{
  run: Run
}>()

const { confirm } = useConfirm()

const calculate = () => {
  router.post(route('payroll.runs.calculate', props.run.id))
}

const approve = () => {
  confirm({
    title: `Approve Payroll Run "${props.run.run_number}"?`,
    description: 'This validates the calculation for payment disbursement.',
    confirmText: 'Approve Run',
    onConfirm: () => router.post(route('payroll.runs.approve', props.run.id)),
  })
}

const markPaid = () => {
  confirm({
    title: `Mark Payroll Run "${props.run.run_number}" as Paid?`,
    description: 'This records that funds have been transferred to employee bank accounts.',
    confirmText: 'Confirm Paid',
    onConfirm: () => router.post(route('payroll.runs.markPaid', props.run.id)),
  })
}

const lock = () => {
  confirm({
    title: `Lock Payroll Run "${props.run.run_number}"?`,
    description: 'Locked runs can no longer be recalculated or modified.',
    variant: 'destructive',
    confirmText: 'Lock Run',
    onConfirm: () => router.post(route('payroll.runs.lock', props.run.id)),
  })
}
</script>

<template>
  <AppLayout :title="`Payroll Run ${run.run_number}`">
    <PageHeader
      :title="`Payroll Run: ${run.run_number}`"
      :subtitle="`${run.run_type.toUpperCase().replace('_', ' ')} • Period ${formatDate(run.period_start)} to ${formatDate(run.period_end)}`"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('payroll.runs.index')">&larr; Back</SecondaryButton>

          <!-- Calculate button -->
          <PrimaryButton
            v-if="!run.is_locked && (run.status === 'draft' || run.status === 'calculated')"
            type="button"
            @click="calculate"
          >
            {{ run.status === 'draft' ? 'Calculate Run' : 'Recalculate' }}
          </PrimaryButton>

          <!-- Approve button -->
          <SecondaryButton
            v-if="run.status === 'calculated'"
            type="button"
            class="!text-emerald-700 !border-emerald-300 hover:!bg-emerald-50"
            @click="approve"
          >
            Approve Run
          </SecondaryButton>

          <!-- Mark Paid button -->
          <SecondaryButton
            v-if="run.status === 'approved'"
            type="button"
            class="!text-emerald-700 !border-emerald-300 hover:!bg-emerald-50"
            @click="markPaid"
          >
            Mark as Paid
          </SecondaryButton>

          <!-- Lock button -->
          <DangerButton
            v-if="!run.is_locked && (run.status === 'approved' || run.status === 'paid')"
            type="button"
            @click="lock"
          >
            Lock
          </DangerButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-6">
      <!-- Summary Bar -->
      <div class="grid grid-cols-2 gap-4 rounded-lg border border-border bg-surface-0 p-4 sm:grid-cols-5">
        <div>
          <div class="text-xs text-ink-500">Status</div>
          <div class="mt-1">
            <StatusBadge :status="run.status" />
          </div>
        </div>
        <div>
          <div class="text-xs text-ink-500">Total Gross</div>
          <div class="mt-1 font-mono font-bold text-ink-900">{{ formatCurrency(Number(run.total_gross)) }}</div>
        </div>
        <div>
          <div class="text-xs text-ink-500">Total PPh 21</div>
          <div class="mt-1 font-mono font-bold text-ink-900">{{ formatCurrency(Number(run.total_tax_pph21)) }}</div>
        </div>
        <div>
          <div class="text-xs text-ink-500">BPJS (Employer+EE)</div>
          <div class="mt-1 font-mono font-bold text-ink-900">
            {{ formatCurrency(Number(run.total_bpjs_employer) + Number(run.total_bpjs_employee)) }}
          </div>
        </div>
        <div>
          <div class="text-xs text-ink-500">Total Net Pay</div>
          <div class="mt-1 font-mono font-bold text-emerald-600">{{ formatCurrency(Number(run.total_net)) }}</div>
        </div>
      </div>

      <!-- Payslips Table -->
      <Panel :title="`Employee Payslips (${run.lines.length} Employees)`">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-50 text-xs font-medium text-ink-500 uppercase">
              <tr>
                <th class="px-4 py-3">Employee</th>
                <th class="px-4 py-3">PTKP / TER</th>
                <th class="px-4 py-3 text-right">Basic Salary</th>
                <th class="px-4 py-3 text-right">PPh 21</th>
                <th class="px-4 py-3 text-right">BPJS (EE)</th>
                <th class="px-4 py-3 text-right">Net Take-Home</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-if="run.lines.length === 0">
                <td colspan="7" class="p-6 text-center text-ink-500">
                  No calculated payslips yet. Click <strong>Calculate Run</strong> above to generate payslips.
                </td>
              </tr>
              <tr v-for="line in run.lines" :key="line.id" class="hover:bg-surface-50">
                <td class="px-4 py-3">
                  <div class="font-medium text-ink-900">{{ line.employee.full_name }}</div>
                  <div class="text-xs font-mono text-ink-500">
                    {{ line.employee.employee_no }} &bull; <span class="font-sans">{{ line.employee.position?.job?.title ?? '-' }}</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-xs">
                  <span class="font-semibold text-ink-800">{{ line.ptkp_status_code }}</span>
                  <div class="text-ink-500" v-if="line.ter_category">
                    TER {{ line.ter_category }} ({{ (Number(line.ter_rate_percentage) * 100).toFixed(2) }}%)
                  </div>
                </td>
                <td class="px-4 py-3 text-right font-mono text-ink-900">{{ formatCurrency(Number(line.basic_salary)) }}</td>
                <td class="px-4 py-3 text-right font-mono text-ink-700">{{ formatCurrency(Number(line.pph21_amount)) }}</td>
                <td class="px-4 py-3 text-right font-mono text-ink-700">
                  {{ formatCurrency(Number(line.bpjs_kesehatan_employee) + Number(line.bpjs_tk_employee)) }}
                </td>
                <td class="px-4 py-3 text-right font-mono font-bold text-ink-900">
                  {{ formatCurrency(Number(line.take_home_pay)) }}
                </td>
                <td class="px-4 py-3 text-right">
                  <Link
                    :href="route('payroll.payslips.show', line.id)"
                    class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                  >
                    View Slip &rarr;
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
