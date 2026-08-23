<!-- ponytail: Accounting §3D AR Aging — one summary table, partner name links to the AR
     Invoices index filtered by partner (drill-in reuses that screen, no dedicated drill-down UI). -->
<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

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
    <PageHeader title="AR Aging" :description="`As of ${asOf} — open invoice balances by partner and days overdue.`" />

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Partner</th>
            <th class="py-2 text-right">Current</th>
            <th class="py-2 text-right">1-30</th>
            <th class="py-2 text-right">31-60</th>
            <th class="py-2 text-right">61-90</th>
            <th class="py-2 text-right">90+</th>
            <th class="py-2 text-right">Total open</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.partner_id" class="border-b border-border">
            <td class="py-2">
              <Link :href="route('accounting.ar-invoices.index', { company_id: selectedCompanyId, partner_id: r.partner_id })" class="font-medium text-accent hover:underline">
                {{ r.partner_name ?? '—' }}
              </Link>
            </td>
            <td class="py-2 text-right text-ink-900">{{ r.current.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ r.days_1_30.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ r.days_31_60.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ r.days_61_90.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ r.days_90_plus.toFixed(2) }}</td>
            <td class="py-2 text-right font-semibold text-ink-900">{{ rowTotal(r).toFixed(2) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="7" class="py-6 text-center text-ink-600">No open invoices.</td></tr>
        </tbody>
        <tfoot v-if="rows.length">
          <tr class="border-t border-border bg-surface-50 font-semibold">
            <td class="py-2">Total</td>
            <td class="py-2 text-right">{{ columnTotal('current').toFixed(2) }}</td>
            <td class="py-2 text-right">{{ columnTotal('days_1_30').toFixed(2) }}</td>
            <td class="py-2 text-right">{{ columnTotal('days_31_60').toFixed(2) }}</td>
            <td class="py-2 text-right">{{ columnTotal('days_61_90').toFixed(2) }}</td>
            <td class="py-2 text-right">{{ columnTotal('days_90_plus').toFixed(2) }}</td>
            <td class="py-2 text-right">{{ rows.reduce((sum, r) => sum + rowTotal(r), 0).toFixed(2) }}</td>
          </tr>
        </tfoot>
      </table>
    </Panel>
  </AppLayout>
</template>
