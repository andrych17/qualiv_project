<!-- ponytail: Accounting §3N Trial Balance. Column totals matching is the report's own
     correctness check — every posted journal already balances by construction (§3C). -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'

type Row = { account_id: number | null; account_code: string | null; account_name: string; debit: number; credit: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  combined: boolean
  periods: Array<{ value: number; label: string }>
  selectedPeriodId: number | null
  report: { rows: Row[]; totalDebit: number; totalCredit: number } | null
}>()

const update = (params: Record<string, any>) => {
  router.get(route('accounting.reports.trial-balance'), {
    company_id: props.selectedCompanyId,
    combined: props.combined ? 1 : 0,
    fiscal_period_id: props.selectedPeriodId,
    ...params,
  }, { preserveState: true })
}

const switchCompany = (e: Event) => update({ company_id: (e.target as HTMLSelectElement).value })
const switchPeriod = (e: Event) => update({ fiscal_period_id: (e.target as HTMLSelectElement).value })
const toggleCombined = (e: Event) => update({ combined: (e.target as HTMLInputElement).checked ? 1 : 0 })

const tied = () => props.report && Math.abs(props.report.totalDebit - props.report.totalCredit) < 0.005

const exportHref = () => route('accounting.reports.trial-balance.export', {
  company_id: props.selectedCompanyId, combined: props.combined ? 1 : 0, fiscal_period_id: props.selectedPeriodId,
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Trial Balance" description="Net debit or credit per account, through the selected period.">
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

    <Panel v-if="report" class="mt-6">
      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="px-4 py-3">Code</th>
              <th class="px-4 py-3">Account</th>
              <th class="px-4 py-3 text-right">Debit</th>
              <th class="px-4 py-3 text-right">Credit</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="r in report.rows" :key="r.account_code ?? r.account_name" class="hover:bg-surface-50/75 transition-colors">
              <td class="px-4 py-3 font-mono text-xs text-ink-700">{{ r.account_code ?? '—' }}</td>
              <td class="px-4 py-3">
                <Link v-if="r.account_id && !combined" :href="route('accounting.reports.account-ledger', { account: r.account_id, fiscal_period_id: selectedPeriodId })" class="font-medium text-accent hover:underline">
                  {{ r.account_name }}
                </Link>
                <span v-else class="font-medium text-ink-900">{{ r.account_name }}</span>
              </td>
              <td class="px-4 py-3 text-right font-mono text-xs text-ink-900">{{ r.debit ? formatCurrency(r.debit) : '—' }}</td>
              <td class="px-4 py-3 text-right font-mono text-xs text-ink-900">{{ r.credit ? formatCurrency(r.credit) : '—' }}</td>
            </tr>
            <tr v-if="!report.rows.length">
              <td colspan="4" class="px-4 py-8 text-center text-ink-500">No posted activity through this period.</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-border bg-surface-100/75 font-semibold">
              <td class="px-4 py-3 text-ink-900" colspan="2">Total</td>
              <td class="px-4 py-3 text-right font-mono text-xs" :class="tied() ? 'text-ink-900' : 'text-signal-danger'">{{ formatCurrency(report.totalDebit) }}</td>
              <td class="px-4 py-3 text-right font-mono text-xs" :class="tied() ? 'text-ink-900' : 'text-signal-danger'">{{ formatCurrency(report.totalCredit) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>
    <Panel v-else class="mt-6 p-8 text-center text-ink-500">
      <p>Belum ada periode fiskal aktif atau transaksi yang dipilih.</p>
    </Panel>
  </AppLayout>
</template>
