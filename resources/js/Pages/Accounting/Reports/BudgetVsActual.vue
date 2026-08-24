<!-- ponytail: Accounting §3J Budget vs. Actual — variance by account/cost center/period.
     Every row's Actual is normalized on the same footing as its Budget figure (see
     BudgetVsActualService docblock), so variance = actual - budget always means the same
     thing: positive is more activity than budgeted on that account's own normal-balance
     side, whether that reads as overspend (expense) or overperformance (revenue). -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

type Row = {
  account_id: number
  account_code: string
  account_name: string
  fiscal_period_id: number
  period_no: number
  budget: number
  actual: number
  variance: number
  variance_pct: number | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  fiscalYears: Array<{ value: number; label: string }>
  selectedFiscalYearId: number | null
  costCenters: Array<{ value: number; label: string }>
  selectedCostCenterId: number | null
  rows: Row[]
}>()

const goTo = (params: Record<string, string | number | null>) => {
  router.get(route('accounting.reports.budget-vs-actual'), {
    company_id: props.selectedCompanyId,
    fiscal_year_id: props.selectedFiscalYearId,
    cost_center_id: props.selectedCostCenterId ?? '',
    ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => goTo({ company_id: (e.target as HTMLSelectElement).value, fiscal_year_id: null, cost_center_id: '' })
const switchFiscalYear = (e: Event) => goTo({ fiscal_year_id: (e.target as HTMLSelectElement).value })
const switchCostCenter = (e: Event) => goTo({ cost_center_id: (e.target as HTMLSelectElement).value })

const monthLabel = (periodNo: number) => new Date(2000, periodNo - 1, 1).toLocaleString('en', { month: 'short' })

const drillHref = (row: Row) => route('accounting.reports.account-ledger', {
  account: row.account_id,
  fiscal_period_id: row.fiscal_period_id,
  cost_center_id: props.selectedCostCenterId ?? '',
  company_id: props.selectedCompanyId,
  fiscal_year_id: props.selectedFiscalYearId,
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Budget vs. Actual" description="Variance by account, cost center, and period — only cells with a budget or actual figure are shown. Click a row to see the GL detail behind it." />

    <Panel class="mt-6 p-4">
      <div class="flex flex-wrap items-center gap-3">
        <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <select :value="selectedFiscalYearId" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchFiscalYear">
          <option v-for="y in fiscalYears" :key="y.value" :value="y.value">{{ y.label }}</option>
        </select>
        <select :value="selectedCostCenterId ?? ''" class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCostCenter">
          <option value="">Unassigned (no cost center)</option>
          <option v-for="c in costCenters" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
      </div>
    </Panel>

    <Panel class="mt-4 overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="px-3 py-2">Period</th>
            <th class="px-3 py-2">Account</th>
            <th class="px-3 py-2 text-right">Budget</th>
            <th class="px-3 py-2 text-right">Actual</th>
            <th class="px-3 py-2 text-right">Variance</th>
            <th class="px-3 py-2 text-right">Variance %</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in rows" :key="`${r.account_id}-${r.fiscal_period_id}-${i}`" class="cursor-pointer border-b border-border hover:bg-surface-50" @click="router.visit(drillHref(r))">
            <td class="px-3 py-1.5 text-ink-700">{{ monthLabel(r.period_no) }}</td>
            <td class="px-3 py-1.5 text-ink-900">{{ r.account_code }} — {{ r.account_name }}</td>
            <td class="px-3 py-1.5 text-right text-ink-900">{{ r.budget.toFixed(2) }}</td>
            <td class="px-3 py-1.5 text-right text-ink-900">{{ r.actual.toFixed(2) }}</td>
            <td class="px-3 py-1.5 text-right font-medium text-ink-900">{{ r.variance.toFixed(2) }}</td>
            <td class="px-3 py-1.5 text-right text-ink-700">{{ r.variance_pct === null ? '—' : `${r.variance_pct.toFixed(1)}%` }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="px-3 py-6 text-center text-ink-600">Nothing budgeted or posted for this scope.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
