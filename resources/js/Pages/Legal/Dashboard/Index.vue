<!-- ponytail: Legal Main Dashboard (§3A) — summary cards + unified "my work" queue across
     Matters/Deeds/Field Visits/Protocol Books, row-click drawer, mirrors CRM's dashboard
     (resources/js/Pages/CRM/Dashboard/Index.vue) that §3A explicitly says to unify with.
     Ships last — aggregates §3B-§3M. -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'

interface MatterRow { id: number; code: string; title: string; partner_name: string | null; status: string }
interface DeedRow {
  id: number
  deed_number: string | null
  deed_type_name: string | null
  matter_code: string | null
  category: string
  status: string
  danger: boolean
  danger_reason: string | null
}
interface FieldVisitRow { id: number; visit_type_name: string | null; matter_code: string | null; status: string }
interface ProtocolBookRow { id: number; label: string; book_type: string; year: number; status: string }

const props = defineProps<{
  summary: { open_matters: number; deeds_pending_signature: number; tax_pending_clearance: number; bpn_in_process: number }
  myMatters: MatterRow[]
  myDeeds: DeedRow[]
  myFieldVisits: FieldVisitRow[]
  protocolBooks: ProtocolBookRow[]
}>()

const dangerRail = 'border-l-[3px] border-l-signal-danger'

const tabs = ['My Matters', 'My Deeds', 'Field Visits', 'Protocol Books'] as const
const activeTab = ref<(typeof tabs)[number]>('My Matters')

type DrawerType = 'matter' | 'deed' | 'fieldVisit' | 'protocolBook'
type DrawerData = {
  type: DrawerType
  record: Record<string, any>
  documents?: Array<{ id: number; title: string; status: string }>
  taxes?: Array<{ id: number; tax_type: string; taxpayer_name: string | null; computed_amount: string | number | null; status: string }>
  entries?: Array<{ id: number; sequence_number: number; entry_date_formatted: string | null; deed_number: string | null }>
} | null

const drawer = ref<DrawerData>(null)
const drawerLoading = ref(false)

const drawerRouteNames: Record<DrawerType, string> = {
  matter: 'legal.dashboard.matter',
  deed: 'legal.dashboard.deed',
  fieldVisit: 'legal.dashboard.fieldVisit',
  protocolBook: 'legal.dashboard.protocolBook',
}

const openDrawer = async (type: DrawerType, id: number) => {
  drawerLoading.value = true
  try {
    const response = await fetch(route(drawerRouteNames[type], id))
    drawer.value = await response.json()
  } finally {
    drawerLoading.value = false
  }
}

const drawerTitle = () => {
  const r = drawer.value?.record
  if (!r) return ''
  return r.title ?? r.deed_number ?? r.visit_type_name ?? r.label ?? ''
}
const drawerSubtitle = () => {
  const r = drawer.value?.record
  if (!r) return null
  return r.matter_title ?? r.matter_code ?? r.partner_name ?? null
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Legal Dashboard" description="Practice-health snapshot and your assigned work." />

    <LegalSubNav active="dashboard" class="mt-6" />

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title="Open Matters" :value="String(summary.open_matters)" icon="FileText" href="/legal/matters" />
      <StatCard title="Deeds Pending Signature" :value="String(summary.deeds_pending_signature)" icon="Scroll" href="/legal/deeds" />
      <StatCard title="Tax Pending Clearance" :value="String(summary.tax_pending_clearance)" icon="Receipt" href="/legal/taxes" />
      <StatCard title="BPN In Process" :value="String(summary.bpn_in_process)" icon="Landmark" href="/legal/bpn-submissions" />
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

      <table v-if="activeTab === 'My Matters'" class="w-full text-sm">
        <tbody>
          <tr v-for="m in myMatters" :key="m.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('matter', m.id)">{{ m.code }}</button>
              <span class="text-xs text-ink-600"> — {{ m.title }}</span>
            </td>
            <td class="py-2 text-xs text-ink-600">{{ m.partner_name ?? '—' }}</td>
            <td class="py-2"><StatusBadge :status="m.status" /></td>
          </tr>
          <tr v-if="!myMatters.length"><td class="py-4 text-ink-600">No matters assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'My Deeds'" class="w-full text-sm">
        <tbody>
          <tr v-for="d in myDeeds" :key="d.id" class="border-b border-border hover:bg-surface-50" :class="d.danger ? dangerRail : ''">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('deed', d.id)">
                {{ d.deed_number ?? `#${d.id} (unsigned)` }}
              </button>
              <span v-if="d.deed_type_name" class="text-xs text-ink-600"> — {{ d.deed_type_name }}</span>
            </td>
            <td class="py-2 text-xs text-ink-600">{{ d.matter_code ?? '—' }}</td>
            <td class="py-2"><StatusBadge :status="d.status" /></td>
            <td class="py-2 text-xs text-signal-danger">{{ d.danger_reason ?? '' }}</td>
          </tr>
          <tr v-if="!myDeeds.length"><td class="py-4 text-ink-600">No deeds assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'Field Visits'" class="w-full text-sm">
        <tbody>
          <tr v-for="v in myFieldVisits" :key="v.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('fieldVisit', v.id)">
                {{ v.visit_type_name ?? 'Field visit' }}
              </button>
            </td>
            <td class="py-2 text-xs text-ink-600">{{ v.matter_code ?? '—' }}</td>
            <td class="py-2"><StatusBadge :status="v.status" /></td>
          </tr>
          <tr v-if="!myFieldVisits.length"><td class="py-4 text-ink-600">No field visits assigned to you.</td></tr>
        </tbody>
      </table>

      <table v-else class="w-full text-sm">
        <tbody>
          <tr v-for="b in protocolBooks" :key="b.id" class="border-b border-border hover:bg-surface-50">
            <td class="py-2 pl-3">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer('protocolBook', b.id)">{{ b.label }}</button>
            </td>
            <td class="py-2 text-xs capitalize text-ink-600">{{ b.book_type.replace('_', ' ') }}</td>
            <td class="py-2"><StatusBadge :status="b.status" /></td>
          </tr>
          <tr v-if="!protocolBooks.length"><td class="py-4 text-ink-600">No active protocol books.</td></tr>
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
          <h2 class="mt-4 font-serif text-lg font-semibold text-ink-900">{{ drawerTitle() }}</h2>
          <p v-if="drawerSubtitle()" class="mt-1 text-sm text-ink-600">{{ drawerSubtitle() }}</p>
          <div class="mt-2 flex items-center gap-2">
            <StatusBadge v-if="drawer.record.status" :status="drawer.record.status" />
          </div>

          <Link v-if="drawer.record.edit_url" :href="drawer.record.edit_url" class="mt-4 inline-block text-sm font-medium text-accent hover:underline">
            Open full record →
          </Link>

          <template v-if="drawer.type === 'deed' && drawer.taxes">
            <h3 class="mt-6 text-sm font-semibold text-ink-900">Taxes</h3>
            <ul class="mt-2 space-y-1 text-sm">
              <li v-for="t in drawer.taxes" :key="t.id" class="flex items-center justify-between border-b border-border pb-1">
                <span class="capitalize text-ink-900">{{ t.tax_type.replace('_', ' ') }}</span>
                <StatusBadge :status="t.status" />
              </li>
              <li v-if="!drawer.taxes.length" class="text-ink-600">No tax records.</li>
            </ul>
          </template>

          <template v-if="drawer.type === 'protocolBook' && drawer.entries">
            <h3 class="mt-6 text-sm font-semibold text-ink-900">Recent entries</h3>
            <ul class="mt-2 space-y-1 text-sm">
              <li v-for="e in drawer.entries" :key="e.id" class="flex items-center justify-between border-b border-border pb-1">
                <span class="text-ink-900">#{{ e.sequence_number }} — {{ e.deed_number ?? '—' }}</span>
                <span class="text-xs text-ink-600">{{ e.entry_date_formatted }}</span>
              </li>
              <li v-if="!drawer.entries.length" class="text-ink-600">No entries yet.</li>
            </ul>
          </template>

          <template v-if="drawer.documents">
            <h3 class="mt-6 text-sm font-semibold text-ink-900">Documents</h3>
            <ul class="mt-2 space-y-1 text-sm">
              <li v-for="doc in drawer.documents" :key="doc.id" class="flex items-center justify-between border-b border-border pb-1">
                <span class="text-ink-900">{{ doc.title }}</span>
                <StatusBadge :status="doc.status" />
              </li>
              <li v-if="!drawer.documents.length" class="text-ink-600">No linked documents.</li>
            </ul>
          </template>
        </template>
      </div>
    </div>
  </AppLayout>
</template>
