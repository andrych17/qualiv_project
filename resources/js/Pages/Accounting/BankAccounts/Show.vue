<!-- ponytail: Accounting §3F cash book — GL-derived (every journal line that ever hit this account's GL account), not a list of cash_transactions rows. See BankAccountController class docblock. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface CashBookLine {
  journal_id: number
  date: string
  memo: string | null
  source: string
  debit: number
  credit: number
  running_balance: number
}

const props = defineProps<{
  bankAccount: {
    id: number
    company_id: number
    name: string
    bank_name: string | null
    account_number_masked: string | null
    currency_code: string
    is_base_currency: boolean
    gl_account_label: string
  }
  lines: CashBookLine[]
  closingBalance: number
}>()
</script>

<template>
  <AppLayout>
    <PageHeader :title="bankAccount.name" :description="`${bankAccount.bank_name ?? 'Cash'} — ${bankAccount.account_number_masked ?? 'no account number'} — ${bankAccount.gl_account_label}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.bank-accounts.index', { company_id: bankAccount.company_id })">
            &larr; Back to Accounts
          </SecondaryButton>
          <SecondaryButton :href="route('accounting.cash-transfers.create', { company_id: bankAccount.company_id, bank_account_id: bankAccount.id })">
            Transfer
          </SecondaryButton>
          <SecondaryButton
            v-if="bankAccount.is_base_currency"
            :href="route('accounting.bank-reconciliation.show', bankAccount.id)"
          >
            Reconcile
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.cash-transactions.create', { company_id: bankAccount.company_id, bank_account_id: bankAccount.id })">
            Cash In / Out
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Currency</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ bankAccount.currency_code }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Closing Balance (GL Live)</div>
        <div class="mt-2 font-mono text-2xl font-bold text-ink-900">{{ formatCurrency(closingBalance) }}</div>
      </Panel>
    </div>

    <Panel class="mt-6">
      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Date</th>
              <th class="py-3 px-4">Memo</th>
              <th class="py-3 px-4">Source</th>
              <th class="py-3 px-4 text-right">Debit</th>
              <th class="py-3 px-4 text-right">Credit</th>
              <th class="py-3 px-4 text-right">Running Balance</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="(l, i) in lines" :key="`${l.journal_id}-${i}`" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(l.date) }}</td>
              <td class="py-3 px-4">
                <Link :href="route('accounting.journals.show', l.journal_id)" class="font-medium text-accent hover:underline">
                  {{ l.memo ?? `Journal #${l.journal_id}` }}
                </Link>
              </td>
              <td class="py-3 px-4 text-xs capitalize text-ink-700 font-medium">{{ l.source.replace('_', ' ') }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ l.debit ? formatCurrency(l.debit) : '—' }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ l.credit ? formatCurrency(l.credit) : '—' }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(l.running_balance) }}</td>
            </tr>
            <tr v-if="!lines.length">
              <td colspan="6" class="py-8 text-center text-ink-500">No posted cash book transactions yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
