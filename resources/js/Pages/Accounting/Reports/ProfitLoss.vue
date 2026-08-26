<!-- ponytail: Accounting §3N P&L (Laporan Laba Rugi). Single-period activity, current + prior period. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

type Row = { account_id: number | null; account_code: string | null; account_name: string; balance: number }
type Snapshot = { periodLabel: string; periodEnd: string; revenue: Row[]; cogs: Row[]; expense: Row[]; totalRevenue: number; totalCogs: number; grossProfit: number; totalExpense: number; netIncome: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  combined: boolean
  periods: Array<{ value: number; label: string }>
  selectedPeriodId: number | null
  report: { current: Snapshot; prior: Snapshot | null } | null
}>()

const update = (params: Record<string, any>) => {
  router.get(route('accounting.reports.profit-loss'), {
    company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId, ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => update({ company_id: (e.target as HTMLSelectElement).value })
const switchPeriod = (e: Event) => update({ fiscal_period_id: (e.target as HTMLSelectElement).value })
const toggleCombined = (e: Event) => update({ combined: (e.target as HTMLInputElement).checked ? 1 : 0 })

const priorFor = (row: Row, priorRows: Row[] | undefined) => priorRows?.find((p) => (p.account_id ?? p.account_name) === (row.account_id ?? row.account_name))?.balance ?? 0

const exportHref = () => route('accounting.reports.profit-loss.export', {
  company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId,
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Profit &amp; Loss" description="Laporan Laba Rugi — single-period activity, current vs. prior period comparison.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton v-if="report" :href="exportHref()">Export CSV</SecondaryButton>
          <SecondaryButton :href="route('accounting.reports.index')">&larr; Reports</SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="flex flex-wrap items-center gap-4">
        <select :value="selectedCompanyId" class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchCompany">
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <select :value="selectedPeriodId" class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" @change="switchPeriod">
          <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-ink-900 font-medium">
          <input type="checkbox" :checked="combined" class="rounded border-border text-accent focus:ring-accent" @change="toggleCombined" />
          Combined (all companies, matched by period number)
        </label>
      </div>
    </Panel>

    <template v-if="report">
      <div class="mt-6 space-y-6">
        <Panel v-for="[key, title] in [['revenue','Pendapatan (Revenue)'],['cogs','Harga Pokok Penjualan (COGS)'],['expense','Beban (Expense)']] as const" :key="key">
          <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">{{ title }}</div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                  <th class="px-4 py-3">Account</th>
                  <th class="px-4 py-3 text-right">{{ report.current.periodLabel }}</th>
                  <th v-if="report.prior" class="px-4 py-3 text-right">{{ report.prior.periodLabel }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="r in (report.current as any)[key] as Row[]" :key="r.account_id ?? r.account_name" class="hover:bg-surface-50/75 transition-colors">
                  <td class="px-4 py-3">
                    <Link v-if="r.account_id && !combined" :href="route('accounting.reports.account-ledger', { account: r.account_id, fiscal_period_id: selectedPeriodId })" class="font-medium text-accent hover:underline">
                      <span class="font-mono text-xs text-ink-600 mr-2">{{ r.account_code }}</span>{{ r.account_name }}
                    </Link>
                    <span v-else class="italic text-ink-900 font-medium">{{ r.account_name }}</span>
                  </td>
                  <td class="px-4 py-3 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.balance) }}</td>
                  <td v-if="report.prior" class="px-4 py-3 text-right font-mono text-xs text-ink-600">{{ formatCurrency(priorFor(r, (report.prior as any)[key])) }}</td>
                </tr>
                <tr v-if="!(report.current as any)[key].length"><td :colspan="report.prior ? 3 : 2" class="px-4 py-6 text-center text-ink-500">—</td></tr>
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      <Panel class="mt-6 p-4">
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between py-1 border-b border-border/50">
            <dt class="text-ink-600">Total Revenue</dt>
            <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(report.current.totalRevenue) }}</dd>
          </div>
          <div class="flex justify-between py-1 border-b border-border/50">
            <dt class="text-ink-600">Total COGS</dt>
            <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(report.current.totalCogs) }}</dd>
          </div>
          <div class="flex justify-between py-1.5 border-b border-border/50 font-semibold">
            <dt class="text-ink-900">Gross Profit</dt>
            <dd class="font-mono text-ink-900">{{ formatCurrency(report.current.grossProfit) }}</dd>
          </div>
          <div class="flex justify-between py-1 border-b border-border/50">
            <dt class="text-ink-600">Total Expense</dt>
            <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(report.current.totalExpense) }}</dd>
          </div>
          <div class="flex justify-between pt-3 text-base font-bold">
            <dt class="text-ink-900">Net Income</dt>
            <dd class="font-mono" :class="report.current.netIncome >= 0 ? 'text-signal-success' : 'text-signal-danger'">
              {{ formatCurrency(report.current.netIncome) }}
            </dd>
          </div>
        </dl>
      </Panel>
    </template>
  </AppLayout>
</template>
