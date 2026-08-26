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
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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
    <PageHeader :title="`${account.account_code} — ${account.account_name}`" :description="periodLabel ?? (throughDate ? `Through ${formatDate(throughDate)}` : 'All posted activity')">
      <template #actions>
        <SecondaryButton :href="back.href">&larr; {{ back.label }}</SecondaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6 p-4">
      <div class="text-xs uppercase font-semibold text-ink-600">{{ closingBalanceLabel }}</div>
      <div class="mt-1 font-mono text-2xl font-bold text-ink-900">{{ formatCurrency(closingBalance) }}</div>
    </Panel>

    <Panel class="mt-6">
      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="px-4 py-3">Date</th>
              <th class="px-4 py-3">Memo</th>
              <th class="px-4 py-3">Source</th>
              <th class="px-4 py-3 text-right">Debit</th>
              <th class="px-4 py-3 text-right">Credit</th>
              <th v-if="!periodLabel" class="px-4 py-3 text-right">Balance</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="(l, i) in lines" :key="`${l.journal_id}-${i}`" class="hover:bg-surface-50/75 transition-colors">
              <td class="px-4 py-3 font-mono text-xs text-ink-700">{{ formatDate(l.date) }}</td>
              <td class="px-4 py-3">
                <Link :href="route('accounting.journals.show', l.journal_id)" class="font-medium text-accent hover:underline">
                  {{ l.memo ?? `Journal #${l.journal_id}` }}
                </Link>
              </td>
              <td class="px-4 py-3 text-xs capitalize text-ink-700 font-medium">{{ l.source.replace('_', ' ') }}</td>
              <td class="px-4 py-3 text-right font-mono text-xs text-ink-900">{{ l.debit ? formatCurrency(l.debit) : '—' }}</td>
              <td class="px-4 py-3 text-right font-mono text-xs text-ink-900">{{ l.credit ? formatCurrency(l.credit) : '—' }}</td>
              <td v-if="!periodLabel" class="px-4 py-3 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(l.running_balance ?? 0) }}</td>
            </tr>
            <tr v-if="!lines.length">
              <td :colspan="periodLabel ? 5 : 6" class="px-4 py-8 text-center text-ink-500">No posted activity.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
