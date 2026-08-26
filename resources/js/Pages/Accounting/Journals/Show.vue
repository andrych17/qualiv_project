<!-- ponytail: Accounting §3C journal detail — draft is editable/postable/deletable, posted is reversible only, reversed/reversal are read-only cross-links. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate, formatDateTime } from '@/Utils/formatters'

interface LineRow {
  id: number
  account_code: string
  account_name: string
  cost_center_name: string | null
  debit: number
  credit: number
  description: string | null
}

const props = defineProps<{
  journal: {
    id: number
    company_id: number
    journal_date: string
    currency_code: string
    memo: string | null
    source: string
    status: string
    fiscal_period: { id: number; period_no: number; status: string } | null
    created_by: string | null
    posted_by: string | null
    posted_at: string | null
    reversed_journal_id: number | null
    reversal_id: number | null
    total_debit: number
    total_credit: number
    lines: LineRow[]
  }
}>()

const { confirm } = useConfirm()

const post = () => {
  confirm({
    title: 'Post this journal?',
    description: 'Posting is final — corrections after this point require a reversing entry, never an edit.',
    confirmText: 'Post',
    onConfirm: () => router.post(route('accounting.journals.post', props.journal.id)),
  })
}

const reverse = () => {
  confirm({
    title: 'Reverse this journal?',
    description: 'Creates a new posted journal with debits/credits swapped, referencing this one.',
    confirmText: 'Reverse',
    onConfirm: () => router.post(route('accounting.journals.reverse', props.journal.id)),
  })
}

const destroy = () => {
  confirm({
    title: 'Delete this draft journal?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.journals.destroy', props.journal.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="journal.memo ?? `Journal #${journal.id}`" :description="`${formatDate(journal.journal_date)} — ${journal.currency_code} — Period ${journal.fiscal_period?.period_no ?? '—'}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.journals.index', { company_id: journal.company_id })">
            &larr; Back to Journals
          </SecondaryButton>
          <SecondaryButton v-if="journal.status === 'draft'" :href="route('accounting.journals.edit', journal.id)">
            Edit
          </SecondaryButton>
          <DangerButton v-if="journal.status === 'draft'" type="button" @click="destroy">
            Delete
          </DangerButton>
          <PrimaryButton v-if="journal.status === 'draft'" type="button" @click="post">
            Post Journal
          </PrimaryButton>
          <SecondaryButton v-if="journal.status === 'posted'" type="button" @click="reverse">
            Reverse Journal
          </SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-2" :status="journal.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Source</div>
        <div class="mt-2 text-sm font-semibold capitalize text-ink-900">{{ journal.source.replace('_', ' ') }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Created By</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ journal.created_by ?? '—' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Posted</div>
        <div class="mt-2 text-xs font-medium text-ink-900">{{ journal.posted_by ? `${journal.posted_by} — ${formatDateTime(journal.posted_at)}` : '—' }}</div>
      </Panel>
    </div>

    <Panel v-if="journal.reversed_journal_id || journal.reversal_id" class="mt-4 p-4 text-sm">
      <Link v-if="journal.reversed_journal_id" :href="route('accounting.journals.show', journal.reversed_journal_id)" class="text-accent hover:underline font-medium">
        &larr; Reversal of Journal #{{ journal.reversed_journal_id }}
      </Link>
      <Link v-if="journal.reversal_id" :href="route('accounting.journals.show', journal.reversal_id)" class="text-accent hover:underline font-medium">
        Reversed by Journal #{{ journal.reversal_id }} &rarr;
      </Link>
    </Panel>

    <Panel class="mt-6">
      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Account</th>
              <th class="py-3 px-4">Cost Center</th>
              <th class="py-3 px-4">Description</th>
              <th class="py-3 px-4 text-right">Debit</th>
              <th class="py-3 px-4 text-right">Credit</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="l in journal.lines" :key="l.id" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-medium text-ink-900">
                <span class="font-mono text-xs text-ink-600 mr-2">{{ l.account_code }}</span>{{ l.account_name }}
              </td>
              <td class="py-3 px-4 text-xs text-ink-700 font-medium">{{ l.cost_center_name ?? '—' }}</td>
              <td class="py-3 px-4 text-ink-700 text-xs">{{ l.description ?? '—' }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ Number(l.debit) ? formatCurrency(Number(l.debit)) : '—' }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900">{{ Number(l.credit) ? formatCurrency(Number(l.credit)) : '—' }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-border bg-surface-100/75 font-semibold">
              <td class="py-3 px-4 text-ink-900" colspan="3">Total</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900 font-bold">{{ formatCurrency(Number(journal.total_debit)) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-900 font-bold">{{ formatCurrency(Number(journal.total_credit)) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
