<!-- ponytail: Payroll Dashboard — headline totals, latest run stats, and pending queue. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import MetricCard from '@/Components/cards/MetricCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'

interface Metrics {
  last_run_total_net: number
  last_run_tax_pph21: number
  last_run_bpjs_total: number
  active_employees: number
  pending_reimbursements: number
}

interface Queues {
  recent_runs: Array<{
    id: number
    run_number: string
    period_start: string
    period_end: string
    run_type: string
    status: string
    total_net: string
    payroll_group?: { name: string }
  }>
  pending_reimbursements: Array<{
    id: number
    employee: { full_name: string }
    category: { name: string }
    claim_date: string
    amount: string
    description?: string
  }>
}

defineProps<{
  metrics: Metrics
  queues: Queues
}>()

const statusVariant = (st: string) => {
  switch (st) {
    case 'paid':
    case 'locked':
      return 'success'
    case 'approved':
      return 'info'
    case 'calculated':
      return 'warning'
    case 'draft':
      return 'neutral'
    default:
      return 'neutral'
  }
}
</script>

<template>
  <AppLayout title="Payroll Dashboard">
    <PageHeader title="Payroll" subtitle="Indonesian statutory payroll, PPh 21 TER, BPJS, and payslip execution." />

    <div class="space-y-6">
      <PayrollSubNav active="dashboard" />

      <!-- Metric Headline Cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label="Last Run Net Pay"
          :value="`Rp ${Number(metrics.last_run_total_net).toLocaleString('id-ID')}`"
          hint="Disbursed take-home pay"
        />
        <MetricCard
          label="PPh 21 Withholding"
          :value="`Rp ${Number(metrics.last_run_tax_pph21).toLocaleString('id-ID')}`"
          hint="Tax liability from last run"
        />
        <MetricCard
          label="Total BPJS Due"
          :value="`Rp ${Number(metrics.last_run_bpjs_total).toLocaleString('id-ID')}`"
          hint="Employer + employee contribution"
        />
        <MetricCard
          label="Active Employees"
          :value="metrics.active_employees"
          hint="Eligible for payroll"
        />
      </div>

      <!-- Action Queues -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Recent Payroll Runs -->
        <Panel title="Recent Payroll Runs">
          <div v-if="queues.recent_runs.length === 0" class="p-4 text-center text-sm text-ink-500">
            No payroll runs created yet.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="run in queues.recent_runs"
              :key="run.id"
              class="flex items-center justify-between p-3 hover:bg-surface-raised transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ run.run_number }} ({{ run.run_type.toUpperCase() }})</div>
                <div class="text-xs text-ink-500">
                  {{ run.payroll_group?.name ?? 'All Groups' }} &bull; Period: {{ run.period_start }} to {{ run.period_end }}
                </div>
                <div class="text-xs font-semibold text-ink-700 mt-0.5">
                  Net: Rp {{ Number(run.total_net).toLocaleString('id-ID') }}
                </div>
              </div>
              <div class="text-right space-y-1">
                <StatusBadge :status="run.status" :variant="statusVariant(run.status)">
                  {{ run.status }}
                </StatusBadge>
                <div>
                  <Link
                    :href="route('payroll.runs.show', run.id)"
                    class="text-xs font-medium text-accent hover:underline"
                  >
                    View Details &rarr;
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </Panel>

        <!-- Pending Reimbursements -->
        <Panel title="Pending Reimbursement Claims">
          <div v-if="queues.pending_reimbursements.length === 0" class="p-4 text-center text-sm text-ink-500">
            No pending reimbursement claims.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="claim in queues.pending_reimbursements"
              :key="claim.id"
              class="flex items-center justify-between p-3 hover:bg-surface-raised transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ claim.employee.full_name }}</div>
                <div class="text-xs text-ink-500">
                  {{ claim.category.name }} &bull; {{ claim.claim_date }}
                </div>
                <div class="text-xs font-semibold text-ink-700">
                  Rp {{ Number(claim.amount).toLocaleString('id-ID') }}
                </div>
              </div>
              <Link
                :href="route('payroll.reimbursements.index')"
                class="text-xs font-medium text-accent hover:underline"
              >
                Review &rarr;
              </Link>
            </div>
          </div>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
