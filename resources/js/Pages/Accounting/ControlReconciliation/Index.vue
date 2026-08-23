<!-- ponytail: Accounting §3Q AR/AP control reconciliation — read-only trust/audit report,
     not a matching UI (see ControlReconciliationController class docblock: every AR/AP
     posting already goes through the one control account, so there's nothing to pair up). -->
<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

type Report = { controlBalance: number; openItemsTotal: number; variance: number; openItemCount: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  ar: Report
  ap: Report
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.control-reconciliation.index'), { company_id: companyId }, { preserveState: true })
}

const ok = (r: Report) => Math.abs(r.variance) < 0.005
</script>

<template>
  <AppLayout>
    <PageHeader title="AR/AP Control Reconciliation" description="Control account GL balance vs. sum of open subledger items — a trust check, not a manual matching task." />

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>
    </Panel>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Panel class="p-4">
        <div class="text-sm font-semibold text-ink-900">Accounts Receivable</div>
        <dl class="mt-3 space-y-1 text-sm">
          <div class="flex justify-between"><dt class="text-ink-600">AR control account (GL)</dt><dd class="font-medium text-ink-900">{{ ar.controlBalance.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-600">Open invoices ({{ ar.openItemCount }}), base currency</dt><dd class="text-ink-900">{{ ar.openItemsTotal.toFixed(2) }}</dd></div>
          <div class="flex justify-between border-t border-border pt-1 font-semibold">
            <dt :class="ok(ar) ? 'text-ink-900' : 'text-signal-danger'">Variance</dt>
            <dd :class="ok(ar) ? 'text-signal-success' : 'text-signal-danger'">{{ ar.variance.toFixed(2) }}</dd>
          </div>
        </dl>
      </Panel>
      <Panel class="p-4">
        <div class="text-sm font-semibold text-ink-900">Accounts Payable</div>
        <dl class="mt-3 space-y-1 text-sm">
          <div class="flex justify-between"><dt class="text-ink-600">AP control account (GL)</dt><dd class="font-medium text-ink-900">{{ ap.controlBalance.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-600">Open bills ({{ ap.openItemCount }}), base currency</dt><dd class="text-ink-900">{{ ap.openItemsTotal.toFixed(2) }}</dd></div>
          <div class="flex justify-between border-t border-border pt-1 font-semibold">
            <dt :class="ok(ap) ? 'text-ink-900' : 'text-signal-danger'">Variance</dt>
            <dd :class="ok(ap) ? 'text-signal-success' : 'text-signal-danger'">{{ ap.variance.toFixed(2) }}</dd>
          </div>
        </dl>
      </Panel>
    </div>
  </AppLayout>
</template>
