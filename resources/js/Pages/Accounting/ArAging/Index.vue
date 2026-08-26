<!-- ponytail: Accounting §3D AR Aging — one summary table, partner name links to the AR
     Invoices index filtered by partner (drill-in reuses that screen, no dedicated drill-down UI). -->
<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import { formatCurrency } from '@/Utils/formatters'

interface AgingRow {
  partner_id: number
  partner_name: string | null
  current: number
  days_1_30: number
  days_31_60: number
  days_61_90: number
  days_90_plus: number
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  rows: AgingRow[]
  asOf: string
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.ar-aging.index'), { company_id: companyId }, { preserveState: true })
}

const rowTotal = (r: AgingRow) => r.current + r.days_1_30 + r.days_31_60 + r.days_61_90 + r.days_90_plus

const columnTotal = (key: keyof Omit<AgingRow, 'partner_id' | 'partner_name'>) => props.rows.reduce((sum, r) => sum + r[key], 0)
</script>

<template>
  <AppLayout>
    <PageHeader title="AR Aging" :description="`As of ${asOf} — open invoice balances by customer and days overdue.`" />

    <Panel class="mt-6">
      <div class="mb-4 flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Customer</th>
              <th class="py-3 px-4 text-right">Current</th>
              <th class="py-3 px-4 text-right">1-30 Days</th>
              <th class="py-3 px-4 text-right">31-60 Days</th>
              <th class="py-3 px-4 text-right">61-90 Days</th>
              <th class="py-3 px-4 text-right">90+ Days</th>
              <th class="py-3 px-4 text-right font-bold">Total Open</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="r in rows" :key="r.partner_id" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4">
                <Link :href="route('accounting.ar-invoices.index', { company_id: selectedCompanyId, partner_id: r.partner_id })" class="font-medium text-accent hover:underline">
                  {{ r.partner_name ?? '—' }}
                </Link>
              </td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.current) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.days_1_30) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.days_31_60) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.days_61_90) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ formatCurrency(r.days_90_plus) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-bold text-ink-900">{{ formatCurrency(rowTotal(r)) }}</td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="7" class="py-8 text-center text-ink-500">No open customer invoices.</td>
            </tr>
          </tbody>
          <tfoot v-if="rows.length">
            <tr class="border-t-2 border-border bg-surface-100/75 font-semibold">
              <td class="py-3 px-4 text-ink-900">Total</td>
              <td class="py-3 px-4 text-right font-mono text-xs">{{ formatCurrency(columnTotal('current')) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs">{{ formatCurrency(columnTotal('days_1_30')) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs">{{ formatCurrency(columnTotal('days_31_60')) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs">{{ formatCurrency(columnTotal('days_61_90')) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs">{{ formatCurrency(columnTotal('days_90_plus')) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-bold text-accent">{{ formatCurrency(rows.reduce((sum, r) => sum + rowTotal(r), 0)) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
