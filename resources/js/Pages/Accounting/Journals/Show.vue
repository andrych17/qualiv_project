<!-- ponytail: Accounting §3C journal detail — draft is editable/postable/deletable, posted is reversible only, reversed/reversal are read-only cross-links. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

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
    <PageHeader :title="journal.memo ?? `Journal #${journal.id}`" :description="`${journal.journal_date} — ${journal.currency_code} — Period ${journal.fiscal_period?.period_no ?? '—'}`">
      <template #actions>
        <Link :href="route('accounting.journals.index', { company_id: journal.company_id })" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to journals</Link>
        <Link v-if="journal.status === 'draft'" :href="route('accounting.journals.edit', journal.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
        <button v-if="journal.status === 'draft'" type="button" class="mr-3 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <PrimaryButton v-if="journal.status === 'draft'" type="button" @click="post">Post journal</PrimaryButton>
        <PrimaryButton v-if="journal.status === 'posted'" type="button" @click="reverse">Reverse</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-1" :status="journal.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Source</div>
        <div class="mt-1 text-sm font-semibold capitalize text-ink-900">{{ journal.source }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Created by</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ journal.created_by ?? '—' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Posted</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ journal.posted_by ? `${journal.posted_by} — ${journal.posted_at}` : '—' }}</div>
      </Panel>
    </div>

    <Panel v-if="journal.reversed_journal_id || journal.reversal_id" class="mt-4 p-4 text-sm">
      <Link v-if="journal.reversed_journal_id" :href="route('accounting.journals.show', journal.reversed_journal_id)" class="text-accent hover:underline">
        This is the reversal of journal #{{ journal.reversed_journal_id }}
      </Link>
      <Link v-if="journal.reversal_id" :href="route('accounting.journals.show', journal.reversal_id)" class="text-accent hover:underline">
        Reversed by journal #{{ journal.reversal_id }}
      </Link>
    </Panel>

    <Panel class="mt-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Account</th>
            <th class="py-2">Cost center</th>
            <th class="py-2">Description</th>
            <th class="py-2 text-right">Debit</th>
            <th class="py-2 text-right">Credit</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in journal.lines" :key="l.id" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ l.account_code }} — {{ l.account_name }}</td>
            <td class="py-2 text-ink-700">{{ l.cost_center_name ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ l.description ?? '—' }}</td>
            <td class="py-2 text-right text-ink-900">{{ Number(l.debit) ? Number(l.debit).toFixed(2) : '—' }}</td>
            <td class="py-2 text-right text-ink-900">{{ Number(l.credit) ? Number(l.credit).toFixed(2) : '—' }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-border bg-surface-50 font-semibold">
            <td class="py-2" colspan="3">Total</td>
            <td class="py-2 text-right">{{ Number(journal.total_debit).toFixed(2) }}</td>
            <td class="py-2 text-right">{{ Number(journal.total_credit).toFixed(2) }}</td>
          </tr>
        </tfoot>
      </table>
    </Panel>
  </AppLayout>
</template>
