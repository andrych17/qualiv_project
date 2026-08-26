<!-- ponytail: Payroll Dashboard — headline totals, latest run stats, and pending queue. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PayrollSubNav from '@/Components/payroll/PayrollSubNav.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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
</script>

<template>
  <AppLayout title="Payroll Dashboard">
    <PageHeader title="Payroll" subtitle="Indonesian statutory payroll, PPh 21 TER, BPJS, and payslip execution." />

    <div class="mt-4">
      <PayrollSubNav active="dashboard" />
    </div>

    <div class="mt-6 space-y-6">
      <!-- Metric Headline Cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Last Run Net Pay"
          :value="formatCurrency(metrics.last_run_total_net)"
          description="Disbursed take-home pay"
          icon="Wallet"
        />
        <StatCard
          title="PPh 21 Withholding"
          :value="formatCurrency(metrics.last_run_tax_pph21)"
          description="Tax liability from last run"
          icon="Receipt"
        />
        <StatCard
          title="Total BPJS Due"
          :value="formatCurrency(metrics.last_run_bpjs_total)"
          description="Employer + employee contribution"
          icon="ShieldCheck"
        />
        <StatCard
          title="Active Employees"
          :value="String(metrics.active_employees)"
          description="Eligible for payroll"
          icon="Users"
        />
      </div>

      <!-- Action Queues -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Recent Payroll Runs -->
        <Panel title="Recent Payroll Runs">
          <div v-if="queues.recent_runs.length === 0" class="p-6 text-center text-sm text-ink-500">
            No payroll runs created yet.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="run in queues.recent_runs"
              :key="run.id"
              class="flex items-center justify-between p-3.5 hover:bg-surface-50 transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ run.run_number }} ({{ run.run_type.toUpperCase().replace('_', ' ') }})</div>
                <div class="text-xs text-ink-500 font-mono">
                  {{ run.payroll_group?.name ?? 'All Groups' }} &bull; Period: {{ formatDate(run.period_start) }} to {{ formatDate(run.period_end) }}
                </div>
                <div class="text-xs font-semibold text-ink-700 mt-1 font-mono">
                  Net: {{ formatCurrency(Number(run.total_net)) }}
                </div>
              </div>
              <div class="text-right space-y-1.5">
                <StatusBadge :status="run.status" />
                <div>
                  <Link
                    :href="route('payroll.runs.show', run.id)"
                    class="text-xs font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
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
          <div v-if="queues.pending_reimbursements.length === 0" class="p-6 text-center text-sm text-ink-500">
            No pending reimbursement claims.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="claim in queues.pending_reimbursements"
              :key="claim.id"
              class="flex items-center justify-between p-3.5 hover:bg-surface-50 transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ claim.employee.full_name }}</div>
                <div class="text-xs text-ink-500 font-mono">
                  {{ claim.category.name }} &bull; {{ formatDate(claim.claim_date) }}
                </div>
                <div class="text-xs font-semibold text-ink-900 font-mono mt-1">
                  {{ formatCurrency(Number(claim.amount)) }}
                </div>
              </div>
              <Link
                :href="route('payroll.reimbursements.index')"
                class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
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
