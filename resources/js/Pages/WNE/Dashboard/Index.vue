<!-- ponytail: WNE §3A Main Dashboard — summary cards + tabbed table, mirrors CRM's own
     dashboard (resources/js/Pages/CRM/Dashboard/Index.vue). Every tab is a capped, read-only
     preview: My Tasks/DLQ each already have a full dedicated page to drill into; Active
     Instances/SLA Breaches have no instance-detail page yet, so their only link-out is the
     owning workflow definition's builder — an honest "closest existing page," not a fake one. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'

interface TaskRow { id: number; workflow_name: string; step_code: string; status: string; sla_state: string | null; due_at_formatted: string | null }
interface InstanceRow { id: number; definition_id: number; workflow_name: string; subject_type: string | null; subject_id: number | null; started_at_formatted: string | null; rail: 'danger' | 'neutral' }
interface BreachRow { id: number; workflow_name: string; step_code: string; subject_type: string | null; subject_id: number | null; due_at_formatted: string | null }
interface DlqRow { id: number; channel: string; subject: string; recipient_email: string | null; attempts: number; last_error: string | null; created_at_formatted: string }

const props = defineProps<{
  summary: {
    active_instances: number
    my_pending_tasks: number
    sla_breaches_24h: number
    notifications_sent_today: number
    notifications_failure_rate_today: number | null
    dlq_items: number
  }
  myTasks: TaskRow[]
  activeInstances: InstanceRow[]
  slaBreaches: BreachRow[]
  dlqItems: DlqRow[]
}>()

// §3A rail vocabulary: danger = SLA-breached/failed/DLQ, warning = due soon,
// success = completed/delivered, info = system-generated, neutral = normal in-progress.
const railClass = (state: string | null) => {
  const map: Record<string, string> = {
    breached: 'border-l-[3px] border-l-signal-danger',
    danger: 'border-l-[3px] border-l-signal-danger',
    due_soon: 'border-l-[3px] border-l-signal-warning',
    warning: 'border-l-[3px] border-l-signal-warning',
    on_track: 'border-l-[3px] border-l-signal-success',
    success: 'border-l-[3px] border-l-signal-success',
  }
  return map[state ?? ''] ?? 'border-l-[3px] border-l-border'
}

const tabs = ['My Tasks', 'Active Instances', 'SLA Breaches', 'DLQ / Failed Deliveries'] as const
const activeTab = ref<(typeof tabs)[number]>('My Tasks')
</script>

<template>
  <AppLayout>
    <PageHeader title="WNE Dashboard" description="Workflow and notification health, and what's waiting on you." />

    <WneSubNav active="dashboard" class="mt-6" />

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
      <StatCard title="Active Instances" :value="String(summary.active_instances)" icon="Workflow" />
      <StatCard title="My Pending Tasks" :value="String(summary.my_pending_tasks)" icon="ListChecks" href="/wne/my-tasks" />
      <StatCard title="SLA Breaches (24h)" :value="String(summary.sla_breaches_24h)" icon="AlertTriangle" />
      <StatCard
        title="Notifications Sent Today"
        :value="String(summary.notifications_sent_today)"
        :description="summary.notifications_failure_rate_today !== null ? `${summary.notifications_failure_rate_today}% failed today` : undefined"
        icon="Send"
      />
      <StatCard title="DLQ Items" :value="String(summary.dlq_items)" icon="Inbox" href="/wne/dead-letters" />
    </div>

    <Panel class="mt-6">
      <div class="mb-4 flex gap-2 border-b border-border">
        <button
          v-for="tab in tabs"
          :key="tab"
          type="button"
          class="border-b-2 px-3 py-2 text-sm font-medium"
          :class="activeTab === tab ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
          @click="activeTab = tab"
        >
          {{ tab }}
        </button>
      </div>

      <table v-if="activeTab === 'My Tasks'" class="w-full text-sm">
        <tbody>
          <tr v-for="t in myTasks" :key="t.id" class="border-b border-border hover:bg-surface-50" :class="railClass(t.sla_state)">
            <td class="py-2 pl-3">
              <span class="font-medium text-ink-900">{{ t.workflow_name }}</span>
              <span class="text-xs text-ink-600"> — {{ t.step_code }}</span>
            </td>
            <td class="py-2"><StatusBadge :status="t.status" /></td>
            <td class="py-2 text-xs text-ink-600">{{ t.due_at_formatted ?? '—' }}</td>
          </tr>
          <tr v-if="!myTasks.length"><td class="py-4 text-ink-600">Nothing waiting on you.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'Active Instances'" class="w-full text-sm">
        <tbody>
          <tr v-for="i in activeInstances" :key="i.id" class="border-b border-border hover:bg-surface-50" :class="railClass(i.rail)">
            <td class="py-2 pl-3">
              <Link :href="route('wne.workflows.edit', i.definition_id)" class="font-medium text-ink-900 hover:underline">{{ i.workflow_name }}</Link>
              <span v-if="i.subject_type" class="text-xs text-ink-600"> — {{ i.subject_type }}#{{ i.subject_id }}</span>
            </td>
            <td class="py-2 text-xs text-ink-600">Started {{ i.started_at_formatted ?? '—' }}</td>
          </tr>
          <tr v-if="!activeInstances.length"><td class="py-4 text-ink-600">No workflow instances currently running.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'SLA Breaches'" class="w-full text-sm">
        <tbody>
          <tr v-for="b in slaBreaches" :key="b.id" class="border-b border-border hover:bg-surface-50" :class="railClass('breached')">
            <td class="py-2 pl-3">
              <span class="font-medium text-ink-900">{{ b.workflow_name }}</span>
              <span class="text-xs text-ink-600"> — {{ b.step_code }}</span>
              <span v-if="b.subject_type" class="text-xs text-ink-600"> · {{ b.subject_type }}#{{ b.subject_id }}</span>
            </td>
            <td class="py-2 text-xs text-ink-600">Due {{ b.due_at_formatted ?? '—' }}</td>
          </tr>
          <tr v-if="!slaBreaches.length"><td class="py-4 text-ink-600">No steps currently breaching their SLA.</td></tr>
        </tbody>
      </table>

      <table v-else class="w-full text-sm">
        <tbody>
          <tr v-for="d in dlqItems" :key="d.id" class="border-b border-border hover:bg-surface-50" :class="railClass('danger')">
            <td class="py-2 pl-3">
              <span class="font-medium text-ink-900">{{ d.subject }}</span>
              <span class="text-xs text-ink-600"> — {{ d.channel }}<template v-if="d.recipient_email"> · {{ d.recipient_email }}</template></span>
            </td>
            <td class="py-2 text-xs text-ink-600">{{ d.attempts }} attempts</td>
            <td class="py-2 text-xs text-ink-600">{{ d.last_error ?? '—' }}</td>
          </tr>
          <tr v-if="!dlqItems.length"><td class="py-4 text-ink-600">Nothing in the dead letter queue needing attention.</td></tr>
        </tbody>
      </table>

      <div class="mt-4 flex justify-end gap-4 border-t border-border pt-4 text-sm">
        <Link v-if="activeTab === 'My Tasks'" href="/wne/my-tasks" class="font-medium text-accent hover:underline">View all in My Approvals →</Link>
        <Link v-if="activeTab === 'DLQ / Failed Deliveries'" href="/wne/dead-letters" class="font-medium text-accent hover:underline">View all in Dead Letters →</Link>
      </div>
    </Panel>
  </AppLayout>
</template>
