<!-- ponytail: Accounting §3N Cash Flow Statement, indirect method. `variance` (computed net
     change vs. actual cash movement) is the report's own correctness check — see
     CashFlowService docblock for the classification rule and the disposal double-count it guards against. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

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
    <PageHeader title="Cash Flow Statement" description="Indirect method — derived from balance sheet movement + P&amp;L, continuous live calculation.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton v-if="report" :href="exportHref()">Export CSV</SecondaryButton>
          <SecondaryButton :href="route('accounting.reports.index')">&larr; Reports</SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="flex flex-wrap items-center gap-4">
        <select :value="selectedCompanyId" class="rounded-md border border-border bg-surface-0 pl-3 pr-8 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 cursor-pointer" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <select :value="selectedPeriodId" class="rounded-md border border-border bg-surface-0 pl-3 pr-8 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 cursor-pointer" @change="switchPeriod">
          <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-ink-900 font-medium">
          <input type="checkbox" :checked="combined" class="rounded border-border text-accent focus:ring-accent" @change="toggleCombined" />
          Combined (all companies, matched by period number)
        </label>
      </div>
    </Panel>

    <template v-if="report">
      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Panel title="Operating Activities">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Net Income</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(report.netIncome) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Depreciation Add-back</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(report.depreciationAddBack) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Disposal Gain/Loss Reversal</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(report.disposalGainLossReversal) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Other Working Capital</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(report.operatingOther) }}</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt class="text-ink-900">Total Operating</dt>
              <dd class="font-mono text-accent">{{ formatCurrency(report.operatingTotal) }}</dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Investing Activities">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Disposal Proceeds</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(report.disposalProceeds) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Asset Additions</dt>
              <dd class="font-mono text-ink-900">({{ formatCurrency(report.assetAdditions) }})</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt class="text-ink-900">Total Investing</dt>
              <dd class="font-mono text-accent">{{ formatCurrency(report.investingTotal) }}</dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Financing Activities">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between pt-2 font-semibold">
              <dt class="text-ink-900">Total Financing</dt>
              <dd class="font-mono text-accent">{{ formatCurrency(report.financingTotal) }}</dd>
            </div>
          </dl>
        </Panel>
      </div>

      <Panel class="mt-6 p-4" :class="Math.abs(report.variance) < 0.005 ? '' : 'border-signal-danger ring-1 ring-signal-danger/40'">
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between text-base font-bold">
            <dt class="text-ink-900">Net Change in Cash</dt>
            <dd class="font-mono text-ink-900">{{ formatCurrency(report.netChange) }}</dd>
          </div>
          <div class="flex justify-between py-1 border-b border-border/50">
            <dt class="text-ink-600">Actual Cash-Account Movement</dt>
            <dd class="font-mono text-ink-900">{{ formatCurrency(report.actualCashChange) }}</dd>
          </div>
          <div class="flex justify-between pt-2 font-semibold">
            <dt :class="Math.abs(report.variance) < 0.005 ? 'text-ink-900' : 'text-signal-danger'">Variance (should be 0)</dt>
            <dd class="font-mono" :class="Math.abs(report.variance) < 0.005 ? 'text-signal-success' : 'text-signal-danger'">
              {{ formatCurrency(report.variance) }}
            </dd>
          </div>
        </dl>
      </Panel>
    </template>
    <Panel v-else class="mt-6 p-8 text-center text-ink-500">
      <p>Belum ada periode fiskal aktif atau transaksi yang dipilih.</p>
    </Panel>
  </AppLayout>
</template>
