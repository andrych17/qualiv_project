<!-- ponytail: Accounting §3Q AR/AP control reconciliation — read-only trust/audit report,
     not a matching UI (see ControlReconciliationController class docblock: every AR/AP
     posting already goes through the one control account, so there's nothing to pair up). -->
<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import { formatCurrency } from '@/Utils/formatters'

type Report = { controlBalance: number; openItemsTotal: number; variance: number; openItemCount: number }
type InventoryReport = { controlBalance: number; valuationTotal: number | null; variance: number | null }
type PayrollReport = { controlBalance: number; openTotal: number | null; variance: number | null }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  ar: Report
  ap: Report
  inventory: InventoryReport
  payroll: PayrollReport
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

    <div class="mt-6 space-y-6">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Panel title="Accounts Receivable (AR)">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">AR Control Account (GL)</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(ar.controlBalance) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Open Invoices ({{ ar.openItemCount }} items)</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(ar.openItemsTotal) }}</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt :class="ok(ar) ? 'text-ink-900' : 'text-signal-danger'">Variance</dt>
              <dd class="font-mono" :class="ok(ar) ? 'text-signal-success' : 'text-signal-danger'">
                {{ formatCurrency(ar.variance) }}
              </dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Accounts Payable (AP)">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">AP Control Account (GL)</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(ap.controlBalance) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Open Bills ({{ ap.openItemCount }} items)</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(ap.openItemsTotal) }}</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt :class="ok(ap) ? 'text-ink-900' : 'text-signal-danger'">Variance</dt>
              <dd class="font-mono" :class="ok(ap) ? 'text-signal-success' : 'text-signal-danger'">
                {{ formatCurrency(ap.variance) }}
              </dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Inventory Subledger">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Inventory Control Account (GL)</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(inventory.controlBalance) }}</dd>
            </div>
            <div class="flex justify-between pt-2">
              <dt class="text-ink-600">Inventory Valuation Total</dt>
              <dd class="text-xs text-ink-500 italic">Continuous live balance tracked via item movement journals</dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Payroll Subledger">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Net Pay Payable (GL)</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(payroll.controlBalance) }}</dd>
            </div>
            <div class="flex justify-between pt-2">
              <dt class="text-ink-600">Open Unpaid Net Pay</dt>
              <dd class="text-xs text-ink-500 italic">Continuous live balance tracked via payroll run disbursements</dd>
            </div>
          </dl>
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
