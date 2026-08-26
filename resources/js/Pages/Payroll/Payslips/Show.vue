<!-- ponytail: Single Employee Payslip View (Slip Gaji) — itemized earnings, deductions, and statutory breakdowns. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface Detail {
  id: number
  component_name: string
  type: string
  category: string
  amount: string
}

interface Payslip {
  id: number
  basic_salary: string
  gross_total: string
  taxable_earnings: string
  non_taxable_earnings: string
  bpjs_kesehatan_employer: string
  bpjs_kesehatan_employee: string
  bpjs_tk_employer: string
  bpjs_tk_employee: string
  pph21_amount: string
  other_deductions: string
  net_total: string
  take_home_pay: string
  ptkp_status_code: string
  ter_category?: string
  ter_rate_percentage?: string
  payroll_run: {
    id: number
    run_number: string
    period_start: string
    period_end: string
    pay_date: string
    run_type: string
  }
  employee: {
    id: number
    employee_no: string
    full_name: string
    nik?: string
    npwp?: string
    position?: {
      job?: { title: string }
      org_unit?: { name: string }
    }
  }
  details: Detail[]
}

const props = defineProps<{
  payslip: Payslip
}>()

const print = () => {
  window.print()
}
</script>

<template>
  <AppLayout :title="`Slip Gaji - ${payslip.employee.full_name}`">
    <PageHeader
      title="Slip Gaji / Payslip"
      :subtitle="`Run: ${payslip.payroll_run.run_number} • Period: ${formatDate(payslip.payroll_run.period_start)} to ${formatDate(payslip.payroll_run.period_end)}`"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('payroll.runs.show', payslip.payroll_run.id)">
            &larr; Back to Run
          </SecondaryButton>
          <PrimaryButton type="button" @click="print">
            Print Payslip
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 max-w-4xl space-y-6">
      <div class="rounded-lg border border-border bg-surface-0 p-6 shadow-xs">
        <!-- Header Info -->
        <div class="border-b border-border pb-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-xl font-bold text-ink-900 font-serif">SLIP PEMBAYARAN GAJI</h2>
              <div class="text-xs text-ink-500">Period: {{ formatDate(payslip.payroll_run.period_start) }} s/d {{ formatDate(payslip.payroll_run.period_end) }}</div>
            </div>
            <div class="text-right">
              <div class="text-xs font-semibold text-ink-700">Tanggal Bayar: {{ formatDate(payslip.payroll_run.pay_date) }}</div>
              <div class="text-xs text-ink-500">Tipe: {{ payslip.payroll_run.run_type.toUpperCase() }}</div>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-2 gap-4 text-xs sm:grid-cols-4">
            <div>
              <span class="text-ink-500">Nama:</span>
              <div class="font-semibold text-ink-900">{{ payslip.employee.full_name }}</div>
            </div>
            <div>
              <span class="text-ink-500">NIK / ID Karyawan:</span>
              <div class="font-semibold text-ink-900">{{ payslip.employee.employee_no }}</div>
            </div>
            <div>
              <span class="text-ink-500">Jabatan & Dept:</span>
              <div class="font-semibold text-ink-900">
                {{ payslip.employee.position?.job?.title ?? '-' }} ({{ payslip.employee.position?.org_unit?.name ?? '-' }})
              </div>
            </div>
            <div>
              <span class="text-ink-500">PTKP / Status Pajak:</span>
              <div class="font-semibold text-ink-900">
                {{ payslip.ptkp_status_code }} (TER {{ payslip.ter_category ?? 'A' }})
              </div>
            </div>
          </div>
        </div>

        <!-- Breakdown Grid (Earnings vs Deductions) -->
        <div class="grid grid-cols-1 gap-6 py-6 sm:grid-cols-2">
          <!-- Earnings -->
          <div>
            <h3 class="font-bold text-sm text-ink-900 border-b border-border pb-2">PENGHASILAN / EARNINGS</h3>
            <div class="divide-y divide-border text-sm">
              <div
                v-for="d in payslip.details.filter(item => item.type === 'earning')"
                :key="d.id"
                class="flex justify-between py-2"
              >
                <span class="text-ink-700">{{ d.component_name }}</span>
                <span class="font-mono font-medium text-ink-900">{{ formatCurrency(Number(d.amount)) }}</span>
              </div>
              <div class="flex justify-between py-2 font-bold text-ink-900 bg-surface-50 px-2 rounded">
                <span>TOTAL PENGHASILAN BRUTO</span>
                <span class="font-mono">{{ formatCurrency(Number(payslip.gross_total)) }}</span>
              </div>
            </div>
          </div>

          <!-- Deductions -->
          <div>
            <h3 class="font-bold text-sm text-ink-900 border-b border-border pb-2">POTONGAN / DEDUCTIONS</h3>
            <div class="divide-y divide-border text-sm">
              <div
                v-for="d in payslip.details.filter(item => item.type === 'deduction')"
                :key="d.id"
                class="flex justify-between py-2"
              >
                <span class="text-ink-700">{{ d.component_name }}</span>
                <span class="font-mono font-medium text-signal-danger">{{ formatCurrency(Number(d.amount)) }}</span>
              </div>
              <div class="flex justify-between py-2 font-bold text-ink-900 bg-surface-50 px-2 rounded">
                <span>TOTAL POTONGAN</span>
                <span class="font-mono text-signal-danger">
                  {{ formatCurrency(Number(payslip.pph21_amount) + Number(payslip.bpjs_kesehatan_employee) + Number(payslip.bpjs_tk_employee) + Number(payslip.other_deductions)) }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Net Take Home Pay Total Box -->
        <div class="rounded-lg border border-accent/30 bg-accent/5 p-4 flex items-center justify-between">
          <div>
            <div class="text-xs font-semibold text-accent uppercase tracking-wider">GAJI BERSIH (TAKE HOME PAY)</div>
            <div class="text-xs text-ink-500">Ditransfer ke rekening terdaftar karyawan</div>
          </div>
          <div class="text-2xl font-black font-mono text-ink-900">
            {{ formatCurrency(Number(payslip.take_home_pay)) }}
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
