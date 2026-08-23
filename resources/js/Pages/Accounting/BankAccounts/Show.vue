<!-- ponytail: Accounting §3F cash book — GL-derived (every journal line that ever hit this account's GL account), not a list of cash_transactions rows. See BankAccountController class docblock. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
        <Link :href="route('accounting.bank-accounts.index', { company_id: bankAccount.company_id })" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to accounts</Link>
        <Link :href="route('accounting.cash-transfers.create', { company_id: bankAccount.company_id, bank_account_id: bankAccount.id })" class="mr-3 text-sm font-medium text-accent hover:underline">Transfer</Link>
        <Link
          v-if="bankAccount.is_base_currency"
          :href="route('accounting.bank-reconciliation.show', bankAccount.id)"
          class="mr-3 text-sm font-medium text-accent hover:underline"
        >Reconcile</Link>
        <PrimaryButton :href="route('accounting.cash-transactions.create', { company_id: bankAccount.company_id, bank_account_id: bankAccount.id })">Cash in/out</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Currency</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bankAccount.currency_code }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Closing balance</div>
        <div class="mt-1 text-lg font-semibold text-ink-900">{{ closingBalance.toFixed(2) }}</div>
      </Panel>
    </div>

    <Panel class="mt-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Date</th>
            <th class="py-2">Memo</th>
            <th class="py-2">Source</th>
            <th class="py-2 text-right">Debit</th>
            <th class="py-2 text-right">Credit</th>
            <th class="py-2 text-right">Balance</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in lines" :key="`${l.journal_id}-${i}`" class="border-b border-border">
            <td class="py-2 text-ink-700">{{ l.date }}</td>
            <td class="py-2">
              <Link :href="route('accounting.journals.show', l.journal_id)" class="text-accent hover:underline">{{ l.memo ?? `Journal #${l.journal_id}` }}</Link>
            </td>
            <td class="py-2 text-ink-700 capitalize">{{ l.source }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.debit ? l.debit.toFixed(2) : '—' }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.credit ? l.credit.toFixed(2) : '—' }}</td>
            <td class="py-2 text-right font-medium text-ink-900">{{ l.running_balance.toFixed(2) }}</td>
          </tr>
          <tr v-if="!lines.length"><td colspan="6" class="py-6 text-center text-ink-600">No posted activity yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
