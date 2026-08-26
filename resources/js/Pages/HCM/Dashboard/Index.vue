<!-- ponytail: HCM Dashboard — headline metrics cards and pending action queues. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import MetricCard from '@/Components/cards/MetricCard.vue'
import HcmSubNav from '@/Components/hcm/HcmSubNav.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface Metrics {
  active_employees: number
  on_leave_today: number
  pending_leave_approvals: number
  today_exceptions: number
}

interface Queues {
  pending_approvals: Array<{
    id: number
    employee: { id: number; employee_no: string; full_name: string; position?: { job?: { title: string } } }
    leave_type: { name: string }
    start_date: string
    end_date: string
    days_count: number
    reason: string
  }>
  expiring_contracts: Array<{
    id: number
    employee: { id: number; employee_no: string; full_name: string }
    contract_type: string
    end_date: string
    base_salary: string
  }>
  recent_hires: Array<{
    id: number
    employee_no: string
    full_name: string
    hire_date: string
    position?: { job?: { title: string }; org_unit?: { name: string } }
  }>
}

defineProps<{
  metrics: Metrics
  queues: Queues
}>()
</script>

<template>
  <AppLayout title="HCM Dashboard">
    <PageHeader title="Human Capital Management" subtitle="Employee directory, contracts, time attendance, and leave management." />

    <div class="space-y-6">
      <HcmSubNav active="dashboard" />

      <!-- Metric Headline Cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label="Active Employees"
          :value="metrics.active_employees"
          hint="Total active workforce"
        />
        <MetricCard
          label="On Leave Today"
          :value="metrics.on_leave_today"
          hint="Approved leave for today"
        />
        <MetricCard
          label="Pending Leave Approvals"
          :value="metrics.pending_leave_approvals"
          hint="Awaiting manager review"
        />
        <MetricCard
          label="Attendance Exceptions"
          :value="metrics.today_exceptions"
          hint="Late / missing clock today"
        />
      </div>

      <!-- Action Queues -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Pending Leave Approvals -->
        <Panel title="Pending Leave Approvals">
          <div v-if="queues.pending_approvals.length === 0" class="p-4 text-center text-sm text-ink-500">
            No pending leave approvals.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="req in queues.pending_approvals"
              :key="req.id"
              class="flex items-center justify-between p-3 hover:bg-surface-raised transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ req.employee.full_name }} ({{ req.employee.employee_no }})</div>
                <div class="text-xs text-ink-500">
                  {{ req.leave_type.name }} &bull; {{ req.start_date }} to {{ req.end_date }} ({{ req.days_count }} days)
                </div>
              </div>
              <Link
                :href="route('hcm.leave.index')"
                class="text-xs font-medium text-accent hover:underline"
              >
                Review &rarr;
              </Link>
            </div>
          </div>
        </Panel>

        <!-- Expiring Contracts (Next 60 Days) -->
        <Panel title="Expiring Contracts (Next 60 Days)">
          <div v-if="queues.expiring_contracts.length === 0" class="p-4 text-center text-sm text-ink-500">
            No contracts expiring within 60 days.
          </div>
          <div v-else class="divide-y divide-border">
            <div
              v-for="contract in queues.expiring_contracts"
              :key="contract.id"
              class="flex items-center justify-between p-3 hover:bg-surface-raised transition"
            >
              <div>
                <div class="font-medium text-ink-900">{{ contract.employee.full_name }}</div>
                <div class="text-xs text-ink-500">
                  {{ contract.contract_type }} &bull; Ends on {{ contract.end_date }}
                </div>
              </div>
              <Link
                :href="route('hcm.contracts.index')"
                class="text-xs font-medium text-accent hover:underline"
              >
                Manage &rarr;
              </Link>
            </div>
          </div>
        </Panel>
      </div>

      <!-- Recent Hires -->
      <Panel title="Recent Hires">
        <div v-if="queues.recent_hires.length === 0" class="p-4 text-center text-sm text-ink-500">
          No recent hires recorded.
        </div>
        <div v-else class="divide-y divide-border">
          <div
            v-for="hire in queues.recent_hires"
            :key="hire.id"
            class="flex items-center justify-between p-3 hover:bg-surface-raised transition"
          >
            <div>
              <div class="font-medium text-ink-900">{{ hire.full_name }} ({{ hire.employee_no }})</div>
              <div class="text-xs text-ink-500">
                {{ hire.position?.job?.title ?? 'No Position' }} &bull; {{ hire.position?.org_unit?.name ?? '-' }}
              </div>
            </div>
            <div class="text-right">
              <span class="text-xs text-ink-500">Hired on {{ hire.hire_date }}</span>
              <div>
                <Link
                  :href="route('hcm.employees.show', hire.id)"
                  class="text-xs font-medium text-accent hover:underline"
                >
                  View Profile
                </Link>
              </div>
            </div>
          </div>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
