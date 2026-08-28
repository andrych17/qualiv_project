<!-- ponytail: Central Admin Dashboard (CENTRAL_SPECS.md §3A) — Simon's own operational snapshot -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

type TenantRow = { id: string; name: string; plan: string; access_status: string; created_at: string }
type InvoiceRow = { id: number; tenant: { id: string; name: string } | null; amount_total: string | number; currency: string; due_date: string }
type PaymentRow = {
  id: number
  amount: string | number
  submitted_at: string
  tenant: { id: string; name: string } | null
  invoice: { id: number; amount_total: string | number; currency: string; due_date: string } | null
}

const props = defineProps<{
  summary: {
    active_tenants: number
    past_due_tenants: number
    read_only_tenants: number
    payments_pending_review: number
    mrr: number
    invoices_issued_this_period: number
  }
  pendingPayments: PaymentRow[]
  overdueInvoices: InvoiceRow[]
  approachingCutoff: InvoiceRow[]
  recentTenants: TenantRow[]
}>()

const tabs = ['Pending Payment Review', 'Overdue Invoices', 'Approaching Cutoff', 'Recent Tenants'] as const
const activeTab = ref<(typeof tabs)[number]>('Pending Payment Review')

type Drawer = { tenant: TenantRow; auditLog: Array<{ id: number; action: string; before: unknown; after: unknown; created_at: string }> } | null
const drawer = ref<Drawer>(null)
const drawerLoading = ref(false)

const openDrawer = async (tenantId: string) => {
  drawerLoading.value = true
  try {
    const response = await fetch(route('central.tenants.audit-log', tenantId))
    drawer.value = await response.json()
  } finally {
    drawerLoading.value = false
  }
}

const reactivateForm = useForm({ reason: '' })
const reactivate = (tenantId: string) => {
  if (!reactivateForm.reason) return
  reactivateForm.post(route('central.tenants.reactivate', tenantId), {
    onSuccess: () => {
      reactivateForm.reset()
      drawer.value = null
    },
  })
}
</script>

<template>
  <CentralAdminLayout>
    <PageHeader title="Dashboard" description="Platform health, revenue, and what needs attention today.">
      <template #actions>
        <Link :href="route('central.tenants.create')" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800">
          Register Tenant
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title="Active Tenants" :value="String(summary.active_tenants)" icon="Users" />
      <StatCard title="Past Due" :value="String(summary.past_due_tenants)" icon="AlertTriangle" />
      <StatCard title="Read-Only" :value="String(summary.read_only_tenants)" icon="Lock" />
      <StatCard title="Payments Pending Review" :value="String(summary.payments_pending_review)" icon="Receipt" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
      <StatCard title="MRR-equivalent" :value="`${summary.mrr.toLocaleString()} IDR`" icon="TrendingUp" />
      <StatCard title="Invoices Issued This Period" :value="String(summary.invoices_issued_this_period)" icon="FileText" />
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

      <table v-if="activeTab === 'Pending Payment Review'" class="w-full text-sm">
        <tbody>
          <tr v-for="p in pendingPayments" :key="p.id" class="border-b border-border">
            <td class="py-2">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="p.tenant && openDrawer(p.tenant.id)">
                {{ p.tenant?.name ?? '—' }}
              </button>
            </td>
            <td class="py-2 text-ink-600">{{ p.amount }} submitted {{ p.submitted_at }}</td>
            <td class="py-2 text-right">
              <Link v-if="p.invoice" :href="route('central.invoices.show', p.invoice.id)" class="text-sm font-medium text-ink-900 hover:underline">Review</Link>
            </td>
          </tr>
          <tr v-if="!pendingPayments.length"><td class="py-4 text-ink-600">Nothing pending review.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'Overdue Invoices'" class="w-full text-sm">
        <tbody>
          <tr v-for="inv in overdueInvoices" :key="inv.id" class="border-b border-border">
            <td class="py-2">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="inv.tenant && openDrawer(inv.tenant.id)">
                {{ inv.tenant?.name ?? '—' }}
              </button>
            </td>
            <td class="py-2 text-ink-600">{{ inv.amount_total }} {{ inv.currency }} — due {{ inv.due_date }}</td>
            <td class="py-2 text-right"><Link :href="route('central.invoices.show', inv.id)" class="text-sm font-medium text-ink-900 hover:underline">View</Link></td>
          </tr>
          <tr v-if="!overdueInvoices.length"><td class="py-4 text-ink-600">No overdue invoices.</td></tr>
        </tbody>
      </table>

      <table v-else-if="activeTab === 'Approaching Cutoff'" class="w-full text-sm">
        <tbody>
          <tr v-for="inv in approachingCutoff" :key="inv.id" class="border-b border-border">
            <td class="py-2">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="inv.tenant && openDrawer(inv.tenant.id)">
                {{ inv.tenant?.name ?? '—' }}
              </button>
            </td>
            <td class="py-2 text-ink-600">{{ inv.amount_total }} {{ inv.currency }} — due {{ inv.due_date }}</td>
            <td class="py-2 text-right"><Link :href="route('central.invoices.show', inv.id)" class="text-sm font-medium text-ink-900 hover:underline">View</Link></td>
          </tr>
          <tr v-if="!approachingCutoff.length"><td class="py-4 text-ink-600">No tenant approaching cutoff.</td></tr>
        </tbody>
      </table>

      <table v-else class="w-full text-sm">
        <tbody>
          <tr v-for="t in recentTenants" :key="t.id" class="border-b border-border">
            <td class="py-2">
              <button type="button" class="font-medium text-ink-900 hover:underline" @click="openDrawer(t.id)">{{ t.name }}</button>
            </td>
            <td class="py-2 text-ink-600">{{ t.plan }}</td>
            <td class="py-2"><StatusBadge :status="t.access_status" /></td>
            <td class="py-2 text-right"><Link :href="route('central.tenants.edit', t.id)" class="text-sm font-medium text-ink-900 hover:underline">Edit</Link></td>
          </tr>
          <tr v-if="!recentTenants.length"><td class="py-4 text-ink-600">No tenants yet.</td></tr>
        </tbody>
      </table>
    </Panel>

    <!-- Tenant detail drawer -->
    <div v-if="drawer || drawerLoading" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="drawer = null">
      <div class="h-full w-full max-w-md overflow-y-auto bg-surface-0 border-l border-border p-6 shadow-xl text-ink-900">
        <button type="button" class="text-sm text-ink-600 hover:text-ink-900" @click="drawer = null">Close</button>

        <template v-if="drawerLoading">
          <p class="mt-4 text-sm text-ink-600">Loading…</p>
        </template>
        <template v-else-if="drawer">
          <h2 class="mt-4 font-serif text-lg font-semibold text-ink-900">{{ drawer.tenant.name }}</h2>
          <p class="mt-1 text-sm text-ink-600">Plan {{ drawer.tenant.plan }} — <StatusBadge :status="drawer.tenant.access_status" /></p>

          <div v-if="drawer.tenant.access_status === 'read_only'" class="mt-4 space-y-2 border-t border-border pt-4">
            <label class="text-sm font-medium text-ink-700" for="reactivate-reason">Reactivate (requires a reason)</label>
            <input id="reactivate-reason" v-model="reactivateForm.reason" type="text" class="w-full rounded-md border border-border px-2 py-1 text-sm" placeholder="e.g. comped for Q3" />
            <button
              type="button"
              class="rounded-md bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50"
              :disabled="!reactivateForm.reason || reactivateForm.processing"
              @click="reactivate(drawer.tenant.id)"
            >
              Reactivate
            </button>
          </div>

          <h3 class="mt-6 text-sm font-semibold text-ink-900">Audit trail</h3>
          <ul class="mt-2 space-y-2 text-sm">
            <li v-for="entry in drawer.auditLog" :key="entry.id" class="border-b border-border pb-2">
              <span class="font-medium text-ink-900">{{ entry.action }}</span>
              <span class="text-ink-600"> — {{ entry.created_at }}</span>
            </li>
            <li v-if="!drawer.auditLog.length" class="text-ink-600">No audit entries yet.</li>
          </ul>
        </template>
      </div>
    </div>
  </CentralAdminLayout>
</template>
