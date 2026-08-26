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
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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
        <SecondaryButton :href="route('accounting.bank-accounts.show', bankAccount.id)">
          &larr; Back to Account
        </SecondaryButton>
      </template>
    </PageHeader>

    <Panel v-if="unsupported" class="mt-6 p-6 text-sm text-ink-700">
      Reconciliation is only available for bank accounts in the company's base currency. This account is
      denominated in {{ bankAccount.currency_code }}, whose statement amounts cannot be compared to the
      base-currency GL directly.
    </Panel>

    <template v-else>
      <div v-if="worksheet" class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <Panel title="Book Side (GL Ledger)">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Book Closing Balance</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(worksheet.bookClosingBalance) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Less: Outstanding Items ({{ worksheet.outstandingBookItems }})</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(worksheet.outstandingBookTotal) }}</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt class="text-ink-900">Adjusted Book Balance</dt>
              <dd class="font-mono text-accent">{{ formatCurrency(worksheet.adjustedBookBalance) }}</dd>
            </div>
          </dl>
        </Panel>

        <Panel title="Statement Side (Bank)">
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Matched Statement Total</dt>
              <dd class="font-mono font-medium text-ink-900">{{ formatCurrency(worksheet.matchedStatementTotal) }}</dd>
            </div>
            <div class="flex justify-between py-1 border-b border-border/50">
              <dt class="text-ink-600">Uncleared Items ({{ worksheet.unclearedStatementItems }})</dt>
              <dd class="font-mono text-ink-900">{{ formatCurrency(worksheet.unclearedStatementTotal) }}</dd>
            </div>
            <div class="flex justify-between pt-2 font-semibold">
              <dt class="text-ink-900">Adjusted Statement Balance</dt>
              <dd class="font-mono text-accent">{{ formatCurrency(worksheet.adjustedStatementBalance) }}</dd>
            </div>
          </dl>
        </Panel>

        <Panel class="p-4 sm:col-span-2" :class="varianceOk ? '' : 'border-signal-danger ring-1 ring-signal-danger/40'">
          <div class="flex items-center justify-between">
            <div class="text-xs font-semibold uppercase text-ink-600">Reconciliation Variance</div>
            <div class="font-mono text-xl font-bold" :class="varianceOk ? 'text-signal-success' : 'text-signal-danger'">
              {{ formatCurrency(worksheet.variance) }}
            </div>
          </div>
        </Panel>
      </div>

      <div class="mt-6 flex justify-end">
        <PrimaryButton :disabled="autoMatching || !unmatchedStatementLines.length" @click="runAutoMatch">
          {{ autoMatching ? 'Matching…' : 'Run Auto-Match' }}
        </PrimaryButton>
      </div>

      <!-- Unmatched Statement Lines -->
      <Panel title="Unmatched Statement Lines" class="mt-6">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="py-3 px-4">Date</th>
                <th class="py-3 px-4">Description / Ref</th>
                <th class="py-3 px-4 text-right">Amount</th>
                <th class="py-3 px-4">Matching Candidate</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="l in unmatchedStatementLines" :key="l.id" class="hover:bg-surface-50/75 transition-colors">
                <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(l.line_date) }}</td>
                <td class="py-3 px-4">
                  <div class="font-medium text-ink-900">{{ l.description ?? '—' }}</div>
                  <div v-if="l.reference" class="text-xs text-ink-500 font-mono">{{ l.reference }}</div>
                </td>
                <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(l.amount) }}</td>
                <td class="py-3 px-4">
                  <select
                    v-if="candidatesFor(l).length"
                    v-model="picks[l.id]"
                    class="rounded-md border border-border bg-surface-0 px-2 py-1 text-xs text-ink-900 shadow-xs focus:border-accent focus:outline-none"
                  >
                    <option :value="null">Select GL line…</option>
                    <option v-for="c in candidatesFor(l)" :key="c.id" :value="c.id">
                      {{ formatDate(c.date) }} — {{ c.memo ?? `Journal #${c.journal_id}` }}
                    </option>
                  </select>
                  <span v-else class="text-xs text-ink-400 italic">No exact amount candidate</span>
                </td>
                <td class="py-3 px-4 text-right">
                  <div class="flex items-center justify-end gap-2">
                    <button
                      v-if="picks[l.id]"
                      type="button"
                      class="text-xs font-semibold text-accent hover:underline"
                      @click="submitMatch(l)"
                    >
                      Match
                    </button>
                    <button
                      type="button"
                      class="text-xs font-medium text-ink-500 hover:text-ink-900 hover:underline"
                      @click="ignoreLine(l.id)"
                    >
                      Ignore
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!unmatchedStatementLines.length">
                <td colspan="5" class="py-6 text-center text-ink-500">All statement lines matched or cleared.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <!-- Matched Pairs -->
      <Panel title="Matched Pairs" class="mt-6">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                <th class="py-3 px-4">Statement Date</th>
                <th class="py-3 px-4">Statement Item</th>
                <th class="py-3 px-4 text-right">Amount</th>
                <th class="py-3 px-4">Matched GL Journal</th>
                <th class="py-3 px-4 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border bg-surface">
              <tr v-for="m in matchedLines" :key="m.id" class="hover:bg-surface-50/75 transition-colors">
                <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(m.line_date) }}</td>
                <td class="py-3 px-4 font-medium text-ink-900">{{ m.description ?? '—' }}</td>
                <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(m.amount) }}</td>
                <td class="py-3 px-4">
                  <Link v-if="m.journal_id" :href="route('accounting.journals.show', m.journal_id)" class="text-xs font-medium text-accent hover:underline">
                    {{ m.journal_memo ?? `Journal #${m.journal_id}` }} ({{ m.journal_date ? formatDate(m.journal_date) : '' }})
                  </Link>
                </td>
                <td class="py-3 px-4 text-right">
                  <button type="button" class="text-xs font-medium text-signal-danger hover:underline" @click="unmatchLine(m.id)">
                    Unmatch
                  </button>
                </td>
              </tr>
              <tr v-if="!matchedLines.length">
                <td colspan="5" class="py-6 text-center text-ink-500">No matched pairs yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>
    </template>
  </AppLayout>
</template>
