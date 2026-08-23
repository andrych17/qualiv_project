<!-- ponytail: Accounting §3Q bank reconciliation workspace. Auto-match only commits exact-amount,
     in-window, unambiguous pairs (see BankReconciliationService docblock) — manual matching
     covers the rest via a per-row picker scoped to same-amount candidates only. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type StatementLine = { id: number; line_date: string; description: string | null; reference: string | null; amount: number }
type JournalLine = { id: number; journal_id: number; date: string; memo: string | null; debit: number; credit: number }
type MatchedLine = { id: number; line_date: string; description: string | null; amount: number; matched_at: string | null; journal_id: number | null; journal_date: string | null; journal_memo: string | null }

const props = defineProps<{
  bankAccount: { id: number; company_id: number; name: string; currency_code: string; gl_account_label: string }
  unsupported: boolean
  unmatchedStatementLines: StatementLine[]
  unmatchedJournalLines: JournalLine[]
  matchedLines: MatchedLine[]
  ignoredLines: StatementLine[]
  worksheet: {
    bookClosingBalance: number
    outstandingBookItems: number
    outstandingBookTotal: number
    adjustedBookBalance: number
    matchedStatementTotal: number
    unclearedStatementItems: number
    unclearedStatementTotal: number
    adjustedStatementBalance: number
    variance: number
  } | null
}>()

const picks = ref<Record<number, number | null>>({})

const candidatesFor = (line: StatementLine) => props.unmatchedJournalLines.filter((jl) => Math.round((jl.debit - jl.credit) * 100) === Math.round(line.amount * 100) || Math.round((jl.credit - jl.debit) * 100) === Math.round(line.amount * 100))

const matchForm = useForm({ statement_line_id: null as number | null, journal_line_id: null as number | null })

const submitMatch = (line: StatementLine) => {
  const journalLineId = picks.value[line.id]
  if (!journalLineId) return
  matchForm.statement_line_id = line.id
  matchForm.journal_line_id = journalLineId
  matchForm.post(route('accounting.bank-reconciliation.match', props.bankAccount.id), {
    onSuccess: () => { picks.value[line.id] = null },
  })
}

const autoMatching = ref(false)
const runAutoMatch = () => {
  autoMatching.value = true
  router.post(route('accounting.bank-reconciliation.auto-match', props.bankAccount.id), {}, { onFinish: () => { autoMatching.value = false } })
}

const unmatchLine = (lineId: number) => router.post(route('accounting.bank-reconciliation.unmatch', [props.bankAccount.id, lineId]))
const ignoreLine = (lineId: number) => router.post(route('accounting.bank-reconciliation.ignore', [props.bankAccount.id, lineId]))
const unignoreLine = (lineId: number) => router.post(route('accounting.bank-reconciliation.unignore', [props.bankAccount.id, lineId]))

const varianceOk = computed(() => props.worksheet && Math.abs(props.worksheet.variance) < 0.005)
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Reconcile — ${bankAccount.name}`" :description="`${bankAccount.gl_account_label} — ${bankAccount.currency_code}`">
      <template #actions>
        <Link :href="route('accounting.bank-accounts.show', bankAccount.id)" class="text-sm font-medium text-accent hover:underline">← Back to account</Link>
      </template>
    </PageHeader>

    <Panel v-if="unsupported" class="mt-6 p-6 text-sm text-ink-700">
      Reconciliation is only available for bank accounts in the company's base currency. This account is
      denominated in {{ bankAccount.currency_code }}, whose statement amounts can't be compared to the
      base-currency GL directly.
    </Panel>

    <template v-else>
      <div v-if="worksheet" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Panel class="p-4">
          <div class="text-xs uppercase text-ink-600">Book side</div>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-ink-600">Book closing balance</dt><dd class="font-medium text-ink-900">{{ worksheet.bookClosingBalance.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Less: outstanding items ({{ worksheet.outstandingBookItems }})</dt><dd class="text-ink-900">{{ worksheet.outstandingBookTotal.toFixed(2) }}</dd></div>
            <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt class="text-ink-900">Adjusted book balance</dt><dd class="text-ink-900">{{ worksheet.adjustedBookBalance.toFixed(2) }}</dd></div>
          </dl>
        </Panel>
        <Panel class="p-4">
          <div class="text-xs uppercase text-ink-600">Statement side</div>
          <dl class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><dt class="text-ink-600">Matched statement total</dt><dd class="font-medium text-ink-900">{{ worksheet.matchedStatementTotal.toFixed(2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-600">Uncleared items ({{ worksheet.unclearedStatementItems }})</dt><dd class="text-ink-900">{{ worksheet.unclearedStatementTotal.toFixed(2) }}</dd></div>
            <div class="flex justify-between border-t border-border pt-1 font-semibold"><dt class="text-ink-900">Adjusted statement balance</dt><dd class="text-ink-900">{{ worksheet.adjustedStatementBalance.toFixed(2) }}</dd></div>
          </dl>
        </Panel>
        <Panel class="p-4 sm:col-span-2" :class="varianceOk ? '' : 'ring-1 ring-signal-danger/40'">
          <div class="flex items-center justify-between">
            <div class="text-xs uppercase text-ink-600">Variance</div>
            <div class="text-lg font-semibold" :class="varianceOk ? 'text-signal-success' : 'text-signal-danger'">{{ worksheet.variance.toFixed(2) }}</div>
          </div>
        </Panel>
      </div>

      <div class="mt-6 flex justify-end">
        <PrimaryButton :disabled="autoMatching || !unmatchedStatementLines.length" @click="runAutoMatch">
          {{ autoMatching ? 'Matching…' : 'Auto-match' }}
        </PrimaryButton>
      </div>

      <Panel class="mt-4">
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Unmatched statement lines ({{ unmatchedStatementLines.length }})</div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Date</th>
              <th class="px-4 py-2">Description</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2">Match to</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in unmatchedStatementLines" :key="l.id" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ l.line_date }}</td>
              <td class="px-4 py-2 text-ink-900">{{ l.description ?? '—' }} <span v-if="l.reference" class="text-ink-500">({{ l.reference }})</span></td>
              <td class="px-4 py-2 text-right" :class="l.amount < 0 ? 'text-signal-danger' : 'text-ink-900'">{{ l.amount.toFixed(2) }}</td>
              <td class="px-4 py-2">
                <select v-model="picks[l.id]" class="w-full rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-sm">
                  <option :value="null">— select journal line —</option>
                  <option v-for="jl in candidatesFor(l)" :key="jl.id" :value="jl.id">
                    {{ jl.date }} — {{ jl.memo ?? `Journal #${jl.journal_id}` }} — {{ (jl.debit || jl.credit).toFixed(2) }}
                  </option>
                </select>
                <p v-if="!candidatesFor(l).length" class="mt-1 text-xs text-ink-500">No same-amount journal line to match.</p>
              </td>
              <td class="px-4 py-2 text-right">
                <button type="button" class="mr-3 text-sm font-medium text-accent hover:underline disabled:opacity-40" :disabled="!picks[l.id]" @click="submitMatch(l)">Match</button>
                <button type="button" class="text-sm font-medium text-ink-600 hover:underline" @click="ignoreLine(l.id)">Ignore</button>
              </td>
            </tr>
            <tr v-if="!unmatchedStatementLines.length"><td colspan="5" class="px-4 py-6 text-center text-ink-600">All statement lines matched or ignored.</td></tr>
          </tbody>
        </table>
      </Panel>

      <Panel class="mt-4">
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Unmatched journal lines ({{ unmatchedJournalLines.length }})</div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Date</th>
              <th class="px-4 py-2">Memo</th>
              <th class="px-4 py-2 text-right">Debit</th>
              <th class="px-4 py-2 text-right">Credit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="jl in unmatchedJournalLines" :key="jl.id" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ jl.date }}</td>
              <td class="px-4 py-2">
                <Link :href="route('accounting.journals.show', jl.journal_id)" class="text-accent hover:underline">{{ jl.memo ?? `Journal #${jl.journal_id}` }}</Link>
              </td>
              <td class="px-4 py-2 text-right text-ink-900">{{ jl.debit ? jl.debit.toFixed(2) : '—' }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ jl.credit ? jl.credit.toFixed(2) : '—' }}</td>
            </tr>
            <tr v-if="!unmatchedJournalLines.length"><td colspan="4" class="px-4 py-6 text-center text-ink-600">No outstanding journal activity.</td></tr>
          </tbody>
        </table>
      </Panel>

      <Panel class="mt-4">
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Matched ({{ matchedLines.length }})</div>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
              <th class="px-4 py-2">Statement date</th>
              <th class="px-4 py-2">Description</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2">Journal</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in matchedLines" :key="l.id" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ l.line_date }}</td>
              <td class="px-4 py-2 text-ink-900">{{ l.description ?? '—' }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ l.amount.toFixed(2) }}</td>
              <td class="px-4 py-2">
                <Link v-if="l.journal_id" :href="route('accounting.journals.show', l.journal_id)" class="text-accent hover:underline">{{ l.journal_memo ?? `Journal #${l.journal_id}` }}</Link>
              </td>
              <td class="px-4 py-2 text-right">
                <button type="button" class="text-sm font-medium text-ink-600 hover:underline" @click="unmatchLine(l.id)">Unmatch</button>
              </td>
            </tr>
            <tr v-if="!matchedLines.length"><td colspan="5" class="px-4 py-6 text-center text-ink-600">Nothing matched yet.</td></tr>
          </tbody>
        </table>
      </Panel>

      <Panel v-if="ignoredLines.length" class="mt-4">
        <div class="border-b border-border px-4 py-3 text-sm font-semibold text-ink-900">Ignored ({{ ignoredLines.length }})</div>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="l in ignoredLines" :key="l.id" class="border-b border-border">
              <td class="px-4 py-2 text-ink-700">{{ l.line_date }}</td>
              <td class="px-4 py-2 text-ink-900">{{ l.description ?? '—' }}</td>
              <td class="px-4 py-2 text-right text-ink-900">{{ l.amount.toFixed(2) }}</td>
              <td class="px-4 py-2 text-right">
                <button type="button" class="text-sm font-medium text-accent hover:underline" @click="unignoreLine(l.id)">Restore</button>
              </td>
            </tr>
          </tbody>
        </table>
      </Panel>
    </template>
  </AppLayout>
</template>
