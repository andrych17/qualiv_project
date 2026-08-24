<!-- ponytail: Accounting §3N Balance Sheet (Neraca). Flat account_type grouping (v1
     simplification — see BalanceSheetService docblock), current + prior period. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

type Row = { account_id: number | null; account_code: string | null; account_name: string; balance: number }
type Snapshot = { periodLabel: string; asOfDate: string; assets: Row[]; liabilities: Row[]; equity: Row[]; totalAssets: number; totalLiabilitiesAndEquity: number; variance: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  combined: boolean
  periods: Array<{ value: number; label: string }>
  selectedPeriodId: number | null
  report: { current: Snapshot; prior: Snapshot | null } | null
}>()

const update = (params: Record<string, any>) => {
  router.get(route('accounting.reports.balance-sheet'), {
    company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId, ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => update({ company_id: (e.target as HTMLSelectElement).value })
const switchPeriod = (e: Event) => update({ fiscal_period_id: (e.target as HTMLSelectElement).value })
const toggleCombined = (e: Event) => update({ combined: (e.target as HTMLInputElement).checked ? 1 : 0 })

const priorFor = (row: Row, priorRows: Row[] | undefined) => priorRows?.find((p) => (p.account_id ?? p.account_name) === (row.account_id ?? row.account_name))?.balance ?? 0

const exportHref = () => route('accounting.reports.balance-sheet.export', {
  company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId,
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Balance Sheet" description="Neraca — as of period-end, current vs. prior period.">
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
      <Panel class="mt-4 p-4" :class="Math.abs(report.current.variance) < 0.005 ? '' : 'ring-1 ring-signal-danger/40'">
        <div class="flex items-center justify-between text-sm">
          <span class="text-ink-600">As of {{ report.current.asOfDate }} — Assets vs. Liabilities + Equity</span>
          <span class="font-semibold" :class="Math.abs(report.current.variance) < 0.005 ? 'text-signal-success' : 'text-signal-danger'">Variance {{ report.current.variance.toFixed(2) }}</span>
        </div>
      </Panel>

      <div class="mt-4 space-y-4">
        <Panel v-for="[key, title] in [['assets','Aset (Assets)'],['liabilities','Liabilitas (Liabilities)'],['equity','Ekuitas (Equity)']] as const" :key="key">
          <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">{{ title }}</div>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
                <th class="px-4 py-2">Account</th>
                <th class="px-4 py-2 text-right">{{ report.current.periodLabel }}</th>
                <th v-if="report.prior" class="px-4 py-2 text-right">{{ report.prior.periodLabel }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in (report.current as any)[key] as Row[]" :key="r.account_id ?? r.account_name" class="border-b border-border">
                <td class="px-4 py-2">
                  <Link v-if="r.account_id && !combined" :href="route('accounting.reports.account-ledger', { account: r.account_id, fiscal_period_id: selectedPeriodId })" class="text-accent hover:underline">{{ r.account_code }} {{ r.account_name }}</Link>
                  <span v-else class="italic text-ink-900">{{ r.account_name }}</span>
                </td>
                <td class="px-4 py-2 text-right text-ink-900">{{ r.balance.toFixed(2) }}</td>
                <td v-if="report.prior" class="px-4 py-2 text-right text-ink-700">{{ priorFor(r, (report.prior as any)[key]).toFixed(2) }}</td>
              </tr>
              <tr v-if="!(report.current as any)[key].length"><td :colspan="report.prior ? 3 : 2" class="px-4 py-4 text-center text-ink-600">—</td></tr>
            </tbody>
          </table>
        </Panel>
      </div>

      <Panel class="mt-4 p-4">
        <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
          <div class="flex justify-between border-t border-border pt-2 font-semibold"><dt>Total Assets</dt><dd>{{ report.current.totalAssets.toFixed(2) }}</dd></div>
          <div class="flex justify-between border-t border-border pt-2 font-semibold"><dt>Total Liabilities + Equity</dt><dd>{{ report.current.totalLiabilitiesAndEquity.toFixed(2) }}</dd></div>
        </dl>
      </Panel>
    </template>
  </AppLayout>
</template>
