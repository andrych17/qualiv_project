<!-- ponytail: CRM Main Dashboard — summary cards + assigned work panels across
     Leads/Tickets/Service Cases/Partners with quick inspection drawer -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

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

const page = usePage()

const railClass = (state: string) => {
  const map: Record<string, string> = {
    breached: 'border-l-[3px] border-l-signal-danger',
    due_soon: 'border-l-[3px] border-l-signal-warning',
    on_track: 'border-l-[3px] border-l-signal-success',
  }
  return map[state] ?? 'border-l-[3px] border-l-border'
}

/**
 * Filter panels and metrics based on dynamic ConfigRight permissions in SYSCONFIG
 */
const crmMenuCodes = computed(() => {
  const menus = (page.props.navMenus || []) as Array<{ code: string; children?: Array<{ code: string }> }>
  const crm = menus.find(m => m.code === 'CRM')
  if (!crm || !crm.children) {
    return new Set<string>(['CRM_LEADS', 'CRM_TICKETS', 'CRM_CASES', 'CRM_COMPANIES', 'CRM_CONTACTS'])
  }
  return new Set<string>(crm.children.map(c => c.code))
})

const canAccessLeads = computed(() => crmMenuCodes.value.has('CRM_LEADS'))
const canAccessTickets = computed(() => crmMenuCodes.value.has('CRM_TICKETS'))
const canAccessCases = computed(() => crmMenuCodes.value.has('CRM_CASES'))
const canAccessPartners = computed(() => crmMenuCodes.value.has('CRM_COMPANIES') || crmMenuCodes.value.has('CRM_CONTACTS'))

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

    <!-- Top KPI Cards -->
    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard v-if="canAccessLeads" title="Open Leads" :value="String(summary.open_leads)" icon="TrendingUp" href="/crm/leads" />
      <StatCard v-if="canAccessTickets" title="Open Tickets" :value="String(summary.open_tickets)" icon="LifeBuoy" href="/crm/tickets" />
      <StatCard v-if="canAccessCases" title="Open Service Cases" :value="String(summary.open_service_cases)" icon="Wrench" href="/crm/service-cases" />
      <StatCard v-if="canAccessPartners" title="Partners Added (30d)" :value="String(summary.partners_added_30d)" icon="UserPlus" href="/crm/contacts" />
    </div>

    <!-- Active Work Panels -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <!-- My Leads -->
      <Panel v-if="canAccessLeads" title="My Leads" subtitle="Open leads assigned to you">
        <template #actions>
          <Link href="/crm/leads" class="text-xs font-medium text-accent hover:underline">
            View all →
          </Link>
        </template>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="l in myLeads" :key="l.id" class="border-b border-border hover:bg-surface-50">
                <td class="py-2.5 pl-1">
                  <button type="button" class="text-left font-medium text-ink-900 hover:text-accent hover:underline" @click="openDrawer('lead', l.id)">
                    {{ l.name }}
                  </button>
                  <span v-if="l.company_name" class="text-xs text-ink-600"> — {{ l.company_name }}</span>
                </td>
                <td class="py-2.5 text-right"><StatusBadge :status="l.stage" /></td>
                <td class="py-2.5 text-right text-xs text-ink-600">{{ l.next_action_formatted ?? '—' }}</td>
              </tr>
              <tr v-if="!myLeads.length">
                <td colspan="3" class="py-4 text-center text-xs text-ink-600">No leads assigned to you.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <!-- My Tickets -->
      <Panel v-if="canAccessTickets" title="My Tickets" subtitle="Tickets in your queue">
        <template #actions>
          <Link href="/crm/tickets" class="text-xs font-medium text-accent hover:underline">
            View all →
          </Link>
        </template>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="t in myTickets" :key="t.id" class="border-b border-border hover:bg-surface-50" :class="railClass(t.sla_state)">
                <td class="py-2.5 pl-2">
                  <button type="button" class="text-left font-medium text-ink-900 hover:text-accent hover:underline" @click="openDrawer('ticket', t.id)">
                    {{ t.subject }}
                  </button>
                  <span class="text-xs text-ink-600"> — {{ t.requester_name }}</span>
                </td>
                <td class="py-2.5 text-right"><StatusBadge :status="t.status" /></td>
                <td class="py-2.5 text-right"><StatusBadge :status="t.sla_state" :label="t.sla_state.replace('_', ' ')" /></td>
              </tr>
              <tr v-if="!myTickets.length">
                <td colspan="3" class="py-4 text-center text-xs text-ink-600">No tickets assigned to you.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <!-- My Service Cases -->
      <Panel v-if="canAccessCases" title="My Service Cases" subtitle="Active field service cases">
        <template #actions>
          <Link href="/crm/service-cases" class="text-xs font-medium text-accent hover:underline">
            View all →
          </Link>
        </template>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="c in myServiceCases" :key="c.id" class="border-b border-border hover:bg-surface-50" :class="railClass(c.sla_state)">
                <td class="py-2.5 pl-2">
                  <button type="button" class="text-left font-medium text-ink-900 hover:text-accent hover:underline" @click="openDrawer('case', c.id)">
                    {{ c.subject }}
                  </button>
                  <span v-if="c.partner_name" class="text-xs text-ink-600"> — {{ c.partner_name }}</span>
                </td>
                <td class="py-2.5 text-right"><StatusBadge :status="c.status" /></td>
                <td class="py-2.5 text-right"><StatusBadge :status="c.sla_state" :label="c.sla_state.replace('_', ' ')" /></td>
              </tr>
              <tr v-if="!myServiceCases.length">
                <td colspan="3" class="py-4 text-center text-xs text-ink-600">No service cases assigned to you.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <!-- Recent Partners -->
      <Panel v-if="canAccessPartners" title="Recent Partners" subtitle="Recently registered partners">
        <template #actions>
          <Link href="/crm/contacts" class="text-xs font-medium text-accent hover:underline">
            View all →
          </Link>
        </template>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="p in recentPartners" :key="p.id" class="border-b border-border hover:bg-surface-50">
                <td class="py-2.5 pl-1">
                  <button type="button" class="text-left font-medium text-ink-900 hover:text-accent hover:underline" @click="openDrawer('partner', p.id)">
                    {{ p.name }}
                  </button>
                </td>
                <td class="py-2.5 text-right text-xs capitalize text-ink-600">{{ p.type }}</td>
                <td class="py-2.5 text-right text-xs text-ink-600">{{ p.created_at_formatted }}</td>
              </tr>
              <tr v-if="!recentPartners.length">
                <td colspan="3" class="py-4 text-center text-xs text-ink-600">No partners yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </div>

    <!-- Record drawer -->
    <div v-if="drawer || drawerLoading" class="fixed inset-0 z-50 flex justify-end bg-black/40 backdrop-blur-xs" @click.self="drawer = null">
      <div class="h-full w-full max-w-md overflow-y-auto border-l border-border bg-surface-0 p-6 shadow-xl">
        <div class="flex items-center justify-between border-b border-border pb-3">
          <span class="text-xs font-semibold uppercase tracking-wider text-ink-600">Quick Preview</span>
          <SecondaryButton type="button" class="!py-1 !px-2.5 text-xs" @click="drawer = null">Close</SecondaryButton>
        </div>

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

          <div v-if="drawer.type !== 'partner' && canUpdate" class="mt-4 space-y-3 border-t border-border pt-4">
            <label class="text-sm font-medium text-ink-900">Quick status change</label>
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <FormSelect
                  v-model="statusForm.status"
                  name="status"
                  placeholder="Select status..."
                  :options="STATUS_OPTIONS[drawer.type]"
                />
              </div>
              <PrimaryButton
                type="button"
                :disabled="!statusForm.status || statusForm.processing"
                @click="changeStatus"
              >
                Set
              </PrimaryButton>
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
