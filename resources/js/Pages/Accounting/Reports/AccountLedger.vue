<!-- ponytail: Accounting §3N Trial Balance drill-down — every posted line for one account,
     generalized from BankAccounts/Show.vue's cash book. Also §3J's Budget vs. Actual
     drill-down (same page, period+cost-center scoped mode — no running_balance column,
     since a cumulative balance seeded at zero mid-year would look authoritative while
     being wrong for a single-period slice). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'

type LedgerLine = { journal_id: number; date: string; memo: string | null; source: string; debit: number; credit: number; running_balance?: number }

const props = defineProps<{
  account: { id: number; company_id: number; account_code: string; account_name: string }
  throughDate: string | null
  periodLabel: string | null
  lines: LedgerLine[]
  closingBalance: number
  closingBalanceLabel: string
  back: { href: string; label: string }
}>()
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${account.account_code} — ${account.account_name}`" :description="periodLabel ?? (throughDate ? `Through ${throughDate}` : 'All posted activity')">
      <template #actions>
        <Link :href="back.href" class="text-sm font-medium text-accent hover:underline">← {{ back.label }}</Link>
      </template>
    </PageHeader>

    <Panel class="mt-6 p-4">
      <div class="text-xs uppercase text-ink-600">{{ closingBalanceLabel }}</div>
      <div class="mt-1 text-lg font-semibold text-ink-900">{{ closingBalance.toFixed(2) }}</div>
    </Panel>

    <Panel class="mt-4">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="px-4 py-2">Date</th>
            <th class="px-4 py-2">Memo</th>
            <th class="px-4 py-2">Source</th>
            <th class="px-4 py-2 text-right">Debit</th>
            <th class="px-4 py-2 text-right">Credit</th>
            <th v-if="!periodLabel" class="px-4 py-2 text-right">Balance</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="`${l.journal_id}-${i}`" class="border-b border-border">
            <td class="px-4 py-2 text-ink-700">{{ l.date }}</td>
            <td class="px-4 py-2">
              <Link :href="route('accounting.journals.show', l.journal_id)" class="text-accent hover:underline">{{ l.memo ?? `Journal #${l.journal_id}` }}</Link>
            </td>
            <td class="px-4 py-2 text-ink-700 capitalize">{{ l.source }}</td>
            <td class="px-4 py-2 text-right text-ink-900">{{ l.debit ? l.debit.toFixed(2) : '—' }}</td>
            <td class="px-4 py-2 text-right text-ink-900">{{ l.credit ? l.credit.toFixed(2) : '—' }}</td>
            <td v-if="!periodLabel" class="px-4 py-2 text-right font-medium text-ink-900">{{ (l.running_balance ?? 0).toFixed(2) }}</td>
          </tr>
          <tr v-if="!lines.length"><td :colspan="periodLabel ? 5 : 6" class="px-4 py-6 text-center text-ink-600">No posted activity.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
