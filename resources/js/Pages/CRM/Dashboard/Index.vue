<!-- ponytail: CRM Main Dashboard (§3A) — summary cards + unified "my work" queue across
     Leads/Tickets/Service Cases, row-click drawer mirrors the Central Admin Dashboard
     pattern (resources/js/Pages/Central/Dashboard.vue). Ships last — aggregates §3B-§3G. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'

interface LeadRow { id: number; name: string; company_name: string | null; stage: string; next_action_formatted: string | null }
interface TicketRow { id: number; subject: string; requester_name: string; status: string; sla_state: string }
interface CaseRow { id: number; subject: string; partner_name: string | null; status: string; sla_state: string }
interface PartnerRow { id: number; name: string; type: string; created_at_formatted: string | null }

const props = defineProps<{
  summary: { open_leads: number; open_tickets: number; open_service_cases: number; partners_added_30d: number }
  myLeads: LeadRow[]
  myTickets: TicketRow[]
  myServiceCases: CaseRow[]
  recentPartners: PartnerRow[]
  canUpdate: boolean
}>()

const railClass = (state: string) => {
  const map: Record<string, string> = {
    breached: 'border-l-[3px] border-l-signal-danger',
    due_soon: 'border-l-[3px] border-l-signal-warning',
    on_track: 'border-l-[3px] border-l-signal-success',
  }
  return map[state] ?? 'border-l-[3px] border-l-border'
}

const tabs = ['My Leads', 'My Tickets', 'My Service Cases', 'Recent Partners'] as const
const activeTab = ref<(typeof tabs)[number]>('My Leads')

type DrawerType = 'lead' | 'ticket' | 'case' | 'partner'
type DrawerData = {
  type: DrawerType
  record: Record<string, any>
  activities?: Array<{ id: number; label: string; body: string | null; by: string | null; at: string | null }>
  references?: Record<string, number>
} | null

const drawer = ref<DrawerData>(null)
const drawerLoading = ref(false)

const openDrawer = async (type: DrawerType, id: number) => {
  drawerLoading.value = true
  try {
    const routeName = `crm.dashboard.${type}`
    const response = await fetch(route(routeName, id))
    drawer.value = await response.json()
  } finally {
    drawerLoading.value = false
  }
}

const STATUS_OPTIONS: Record<Exclude<DrawerType, 'partner'>, Array<{ label: string; value: string }>> = {
  lead: [
    { label: 'New', value: 'new' },
    { label: 'Contacted', value: 'contacted' },
    { label: 'Qualified', value: 'qualified' },
  ],
  ticket: [
    { label: 'Open', value: 'open' },
    { label: 'In progress', value: 'in_progress' },
    { label: 'Waiting on partner', value: 'waiting_on_partner' },
    { label: 'Resolved', value: 'resolved' },
    { label: 'Closed', value: 'closed' },
  ],
  case: [
    { label: 'Open', value: 'open' },
    { label: 'In progress', value: 'in_progress' },
    { label: 'Waiting on partner', value: 'waiting_on_partner' },
    { label: 'Resolved', value: 'resolved' },
    { label: 'Closed', value: 'closed' },
  ],
}

const statusForm = useForm({ status: '' })
const changeStatus = () => {
  if (!drawer.value || drawer.value.type === 'partner' || !statusForm.status) return
  const id = drawer.value.record.id
  const routeName = drawer.value.type === 'lead'
    ? 'crm.leads.updateStage'
    : drawer.value.type === 'ticket'
      ? 'crm.tickets.updateStatus'
      : 'crm.serviceCases.updateStatus'
  const field = drawer.value.type === 'lead' ? { stage: statusForm.status } : { status: statusForm.status }
  statusForm.transform(() => field).patch(route(routeName, id), {
    preserveScroll: true,
    onSuccess: () => { drawer.value = null },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="CRM Dashboard" description="At-a-glance CRM health and your assigned work." />

    <CrmSubNav active="dashboard" class="mt-6" />

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title="Open Leads" :value="String(summary.open_leads)" icon="TrendingUp" href="/crm/leads" />
      <StatCard title="Open Tickets" :value="String(summary.open_tickets)" icon="LifeBuoy" href="/crm/tickets" />
      <StatCard title="Open Service Cases" :value="String(summary.open_service_cases)" icon="Wrench" href="/crm/service-cases" />
      <StatCard title="Partners Added (30d)" :value="String(summary.partners_added_30d)" icon="UserPlus" href="/crm/contacts" />
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

      <table v-if="activeTab === 'My Leads'" class="w-full text-sm">
        <tbody>
          <tr v-for="l in myLeads" :key="l.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('lead', l.id)">{{ l.name }}</button>
              <span v-if="l.company_name" class="text-xs text-ink-600"> — {{ l.company_name }}</span>
            </td>
            <td class="py-2"><StatusBadge :status="l.stage" /></td>
            <td class="py-2 text-xs text-ink-600">{{ l.next_action_formatted ?? '—' }}</td>
          </tr>
          <tr v-if="!myLeads.length"><td class="py-4 text-ink-600">No leads assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'My Tickets'" class="w-full text-sm">
        <tbody>
          <tr v-for="t in myTickets" :key="t.id" class="border-b border-border hover:bg-surface-50" :class="railClass(t.sla_state)">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('ticket', t.id)">{{ t.subject }}</button>
              <span class="text-xs text-ink-600"> — {{ t.requester_name }}</span>
            </td>
            <td class="py-2"><StatusBadge :status="t.status" /></td>
            <td class="py-2"><StatusBadge :status="t.sla_state" :label="t.sla_state.replace('_', ' ')" /></td>
          </tr>
          <tr v-if="!myTickets.length"><td class="py-4 text-ink-600">No tickets assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'My Service Cases'" class="w-full text-sm">
        <tbody>
          <tr v-for="c in myServiceCases" :key="c.id" class="border-b border-border hover:bg-surface-50" :class="railClass(c.sla_state)">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('case', c.id)">{{ c.subject }}</button>
              <span v-if="c.partner_name" class="text-xs text-ink-600"> — {{ c.partner_name }}</span>
            </td>
            <td class="py-2"><StatusBadge :status="c.status" /></td>
            <td class="py-2"><StatusBadge :status="c.sla_state" :label="c.sla_state.replace('_', ' ')" /></td>
          </tr>
          <tr v-if="!myServiceCases.length"><td class="py-4 text-ink-600">No service cases assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else class="w-full text-sm">
        <tbody>
          <tr v-for="p in recentPartners" :key="p.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('partner', p.id)">{{ p.name }}</button>
            </td>
            <td class="py-2 text-xs capitalize text-ink-600">{{ p.type }}</td>
            <td class="py-2 text-xs text-ink-600">{{ p.created_at_formatted }}</td>
          </tr>
          <tr v-if="!recentPartners.length"><td class="py-4 text-ink-600">No partners yet.</td></tr>
        </tbody>
      </table>
    </Panel>

    <!-- Record drawer -->
    <div v-if="drawer || drawerLoading" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="drawer = null">
      <div class="h-full w-full max-w-md overflow-y-auto bg-surface-0 p-6 shadow-xl">
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="drawer = null">Close</button>

        <template v-if="drawerLoading">
          <p class="mt-4 text-sm text-ink-600">Loading…</p>
        </template>
        <template v-else-if="drawer">
          <h2 class="mt-4 font-serif text-lg font-semibold text-ink-900">
            {{ drawer.record.name ?? drawer.record.subject }}
          </h2>
          <p v-if="drawer.record.company_name || drawer.record.partner_name || drawer.record.requester_name" class="mt-1 text-sm text-ink-600">
            {{ drawer.record.company_name ?? drawer.record.partner_name ?? drawer.record.requester_name }}
          </p>
          <div class="mt-2 flex items-center gap-2">
            <StatusBadge v-if="drawer.record.stage" :status="drawer.record.stage" />
            <StatusBadge v-if="drawer.record.status" :status="drawer.record.status" />
            <StatusBadge v-if="drawer.record.sla_state" :status="drawer.record.sla_state" :label="drawer.record.sla_state.replace('_', ' ')" />
          </div>

          <Link :href="drawer.record.edit_url" class="mt-4 inline-block text-sm font-medium text-accent hover:underline">
            Open full record →
          </Link>

          <div v-if="drawer.type !== 'partner' && canUpdate" class="mt-4 space-y-2 border-t border-border pt-4">
            <label class="text-sm font-medium text-ink-900">Quick status change</label>
            <div class="flex gap-2">
              <select v-model="statusForm.status" class="w-full rounded-md border border-border px-2 py-1.5 text-sm">
                <option value="">Select…</option>
                <option v-for="o in STATUS_OPTIONS[drawer.type]" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <button
                type="button"
                class="rounded-md bg-ink-900 px-3 py-1.5 text-sm font-semibold text-surface-0 disabled:opacity-50"
                :disabled="!statusForm.status || statusForm.processing"
                @click="changeStatus"
              >
                Set
              </button>
            </div>
          </div>

          <template v-if="drawer.type === 'partner' && drawer.references">
            <h3 class="mt-6 text-sm font-semibold text-ink-900">Cross-references</h3>
            <ul class="mt-2 space-y-1 text-sm text-ink-600">
              <li>Converted leads: {{ drawer.references.leads }}</li>
              <li>Service cases: {{ drawer.references.service_cases }}</li>
              <li>Tickets: {{ drawer.references.tickets }}</li>
              <li>Active roles: {{ drawer.references.roles }}</li>
            </ul>
          </template>

          <template v-else-if="drawer.activities">
            <h3 class="mt-6 text-sm font-semibold text-ink-900">Activity</h3>
            <ul class="mt-2 space-y-2 text-sm">
              <li v-for="a in drawer.activities" :key="a.id" class="border-b border-border pb-2">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-600">
                  {{ a.label }} <span class="font-normal normal-case text-ink-600/70">— {{ a.by ?? 'System' }} · {{ a.at }}</span>
                </p>
                <p v-if="a.body" class="mt-0.5 text-ink-900">{{ a.body }}</p>
              </li>
              <li v-if="!drawer.activities.length" class="text-ink-600">No activity yet.</li>
            </ul>
          </template>
        </template>
      </div>
    </div>
  </AppLayout>
</template>
