<!-- ponytail: Accounting §3N Cash Flow Statement, indirect method. `variance` (computed net
     change vs. actual cash movement) is the report's own correctness check — see
     CashFlowService docblock for the classification rule and the disposal double-count it guards against. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

type Report = {
  periodLabel: string; periodEnd: string
  netIncome: number; depreciationAddBack: number; disposalGainLossReversal: number; operatingOther: number; operatingTotal: number
  disposalProceeds: number; assetAdditions: number; investingTotal: number
  financingTotal: number
  netChange: number; actualCashChange: number; variance: number
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  combined: boolean
  periods: Array<{ value: number; label: string }>
  selectedPeriodId: number | null
  report: Report | null
}>()

const update = (params: Record<string, any>) => {
  router.get(route('accounting.reports.cash-flow'), {
    company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId, ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => update({ company_id: (e.target as HTMLSelectElement).value })
const switchPeriod = (e: Event) => update({ fiscal_period_id: (e.target as HTMLSelectElement).value })
const toggleCombined = (e: Event) => update({ combined: (e.target as HTMLInputElement).checked ? 1 : 0 })

const exportHref = () => route('accounting.reports.cash-flow.export', {
  company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId,
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Cash Flow Statement" description="Indirect method — derived from balance sheet movement + P&amp;L, no separate cash-flow entry.">
      <template #actions>
        <a v-if="report" :href="exportHref()" class="mr-4 text-sm font-medium text-accent hover:underline">Export CSV</a>
        <Link :href="route('accounting.reports.index')" class="text-sm font-medium text-accent hover:underline">← Reports</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="flex flex-wrap items-center gap-4">
        <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <select :value="selectedPeriodId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchPeriod">
          <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input type="checkbox" :checked="combined" class="rounded border-border" @change="toggleCombined" />
          Combined (all companies, matched by period number)
        </label>
      </div>
    </Panel>

    <template v-if="report">
      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Panel class="p-4">
          <div class="text-sm font-semibold text-ink-900">Operating Activities</div>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-ink-600">Net income</dt><dd class="text-ink-900">{{ report.netIncome.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Depreciation add-back</dt><dd class="text-ink-900">{{ report.depreciationAddBack.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Disposal gain/loss reversal</dt><dd class="text-ink-900">{{ report.disposalGainLossReversal.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Other working-capital movement</dt><dd class="text-ink-900">{{ report.operatingOther.toFixed(2) }}</dd></div>
            <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt>Total operating</dt><dd>{{ report.operatingTotal.toFixed(2) }}</dd></div>
          </dl>
        </Panel>
        <Panel class="p-4">
          <div class="text-sm font-semibold text-ink-900">Investing Activities</div>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-ink-600">Disposal proceeds</dt><dd class="text-ink-900">{{ report.disposalProceeds.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Asset additions</dt><dd class="text-ink-900">({{ report.assetAdditions.toFixed(2) }})</dd></div>
            <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt>Total investing</dt><dd>{{ report.investingTotal.toFixed(2) }}</dd></div>
          </dl>
        </Panel>
        <Panel class="p-4">
          <div class="text-sm font-semibold text-ink-900">Financing Activities</div>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt>Total financing</dt><dd>{{ report.financingTotal.toFixed(2) }}</dd></div>
          </dl>
        </Panel>
      </div>

      <Panel class="mt-4 p-4" :class="Math.abs(report.variance) < 0.005 ? '' : 'ring-1 ring-signal-danger/40'">
        <dl class="space-y-1 text-sm">
          <div class="flex justify-between text-base font-bold"><dt>Net change in cash</dt><dd>{{ report.netChange.toFixed(2) }}</dd></div>
          <div class="flex justify-between"><dt class="text-ink-600">Actual cash-account movement</dt><dd class="text-ink-900">{{ report.actualCashChange.toFixed(2) }}</dd></div>
          <div class="flex justify-between border-t border-border pt-1 font-semibold">
            <dt :class="Math.abs(report.variance) < 0.005 ? 'text-ink-900' : 'text-signal-danger'">Variance (should be 0)</dt>
            <dd :class="Math.abs(report.variance) < 0.005 ? 'text-signal-success' : 'text-signal-danger'">{{ report.variance.toFixed(2) }}</dd>
          </div>
        </dl>
      </Panel>
    </template>
  </AppLayout>
</template>
