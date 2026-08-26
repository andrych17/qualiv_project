<!-- Purchase Main Dashboard (§3A) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

interface Metrics {
  open_prs_count: number
  open_pos_count: number
  pending_receipts_count: number
  pending_invoices_count: number
  open_exceptions_count: number
}

interface ExceptionItem {
  id: number
  exception_type: string
  subject_type: string
  subject_id: number
  summary: string
  status: string
  created_at: string | null
}

interface RecentPr {
  id: number
  pr_no: string
  requester_name: string | null
  estimated_total: number
  status: string
  created_at: string | null
}

interface RecentPo {
  id: number
  po_no: string
  supplier_name: string | null
  total_amount: number
  currency_code: string
  status: string
  created_at: string | null
}

const props = defineProps<{
  metrics: Metrics
  exceptions: ExceptionItem[]
  recentPrs: RecentPr[]
  recentPos: RecentPo[]
}>()

const formatCurrency = (val: number, cur: string = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: cur || 'IDR', maximumFractionDigits: 0 }).format(val)
}

const resolveException = (id: number) => {
  router.post(route('purchase.exceptions.resolve', id))
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Procurement Dashboard" description="Overview of purchasing operations, active requisitions, orders, matching, and exceptions (§3A).">
      <template #actions>
        <div class="flex items-center gap-2">
          <PrimaryButton :href="route('purchase.requisitions.create')">New requisition (PR)</PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="dashboard" />
    </div>

    <!-- KPI Summary Cards -->
    <div class="mt-6 grid grid-cols-2 md:grid-cols-5 gap-4">
      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Open PRs</span>
        <div class="mt-2 text-2xl font-bold text-ink-900">{{ metrics.open_prs_count }}</div>
        <Link :href="route('purchase.requisitions.index')" class="mt-2 text-xs text-accent font-medium hover:underline inline-block">
          View all requisitions →
        </Link>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Active POs</span>
        <div class="mt-2 text-2xl font-bold text-ink-900">{{ metrics.open_pos_count }}</div>
        <Link :href="route('purchase.orders.index')" class="mt-2 text-xs text-accent font-medium hover:underline inline-block">
          View all orders →
        </Link>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Pending GR</span>
        <div class="mt-2 text-2xl font-bold text-emerald-700">{{ metrics.pending_receipts_count }}</div>
        <Link :href="route('purchase.receipts.index')" class="mt-2 text-xs text-accent font-medium hover:underline inline-block">
          Receive items →
        </Link>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm">
        <span class="text-xs font-medium text-ink-500 uppercase tracking-wider">Pending Match</span>
        <div class="mt-2 text-2xl font-bold text-purple-700">{{ metrics.pending_invoices_count }}</div>
        <Link :href="route('purchase.invoices.index')" class="mt-2 text-xs text-accent font-medium hover:underline inline-block">
          Match invoices →
        </Link>
      </div>

      <div class="p-4 bg-surface rounded-lg border border-border shadow-sm" :class="metrics.open_exceptions_count > 0 ? 'border-rose-300 bg-rose-50/20' : ''">
        <span class="text-xs font-medium uppercase tracking-wider" :class="metrics.open_exceptions_count > 0 ? 'text-rose-700 font-bold' : 'text-ink-500'">
          Exceptions
        </span>
        <div class="mt-2 text-2xl font-bold" :class="metrics.open_exceptions_count > 0 ? 'text-rose-700' : 'text-ink-900'">
          {{ metrics.open_exceptions_count }}
        </div>
        <Link :href="route('purchase.exceptions.index')" class="mt-2 text-xs text-rose-700 font-medium hover:underline inline-block">
          View exception strip →
        </Link>
      </div>
    </div>

    <!-- Exception Strip (§3A / §3K) -->
    <div v-if="exceptions.length > 0" class="mt-6">
      <Panel title="Active Exception Strip (§3K)">
        <div class="divide-y divide-border">
          <div
            v-for="e in exceptions"
            :key="e.id"
            class="py-3 flex items-center justify-between gap-4"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                :class="{
                  'bg-rose-100 text-rose-800': e.exception_type === 'unmatched_invoice' || e.exception_type === 'price_variance',
                  'bg-amber-100 text-amber-800': e.exception_type === 'late_delivery' || e.exception_type === 'overdue_approval',
                  'bg-blue-100 text-blue-800': e.exception_type === 'budget_flag',
                }"
              >
                {{ e.exception_type.replace(/_/g, ' ') }}
              </span>
              <span class="text-sm font-medium text-ink-900">{{ e.summary }}</span>
            </div>
            <div class="flex items-center gap-2 whitespace-nowrap">
              <span class="text-xs text-ink-500">{{ e.created_at }}</span>
              <button
                type="button"
                class="text-xs font-semibold text-accent hover:underline ml-2"
                @click="resolveException(e.id)"
              >
                Resolve
              </button>
            </div>
          </div>
        </div>
        <div class="mt-3 pt-3 border-t border-border flex justify-end">
          <Link :href="route('purchase.exceptions.index')" class="text-xs text-accent font-semibold hover:underline">
            View all open exceptions in register →
          </Link>
        </div>
      </Panel>
    </div>

    <!-- Recent Tables Grid -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Recent POs -->
      <Panel title="Recent Purchase Orders">
        <div v-if="recentPos.length > 0" class="divide-y divide-border text-sm">
          <div v-for="po in recentPos" :key="po.id" class="py-3 flex items-center justify-between">
            <div>
              <Link :href="route('purchase.orders.show', po.id)" class="font-semibold text-accent hover:underline">
                {{ po.po_no }}
              </Link>
              <div class="text-xs text-ink-500">{{ po.supplier_name ?? '—' }} • {{ formatCurrency(po.total_amount, po.currency_code) }}</div>
            </div>
            <StatusBadge :status="po.status" />
          </div>
        </div>
        <div v-else class="text-sm text-ink-500 py-3">No recent purchase orders.</div>
        <div class="mt-3 pt-3 border-t border-border flex justify-end">
          <Link :href="route('purchase.orders.index')" class="text-xs text-accent font-semibold hover:underline">
            View all orders →
          </Link>
        </div>
      </Panel>

      <!-- Recent PRs -->
      <Panel title="Recent Requisitions (PR)">
        <div v-if="recentPrs.length > 0" class="divide-y divide-border text-sm">
          <div v-for="pr in recentPrs" :key="pr.id" class="py-3 flex items-center justify-between">
            <div>
              <Link :href="route('purchase.requisitions.show', pr.id)" class="font-semibold text-accent hover:underline">
                {{ pr.pr_no }}
              </Link>
              <div class="text-xs text-ink-500">{{ pr.requester_name ?? '—' }} • {{ formatCurrency(pr.estimated_total) }}</div>
            </div>
            <StatusBadge :status="pr.status" />
          </div>
        </div>
        <div v-else class="text-sm text-ink-500 py-3">No recent purchase requisitions.</div>
        <div class="mt-3 pt-3 border-t border-border flex justify-end">
          <Link :href="route('purchase.requisitions.index')" class="text-xs text-accent font-semibold hover:underline">
            View all requisitions →
          </Link>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
