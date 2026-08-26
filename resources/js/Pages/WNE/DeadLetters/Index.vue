<!-- ponytail: WNE §3M — DLQ tab ahead of §3A's Dashboard, which doesn't exist yet to host it.
     List + resend + discard, no filters — the DLQ should stay small enough not to need them
     (a channel only lands here after exhausting an explicitly-configured multi-attempt
     retry policy, which is opt-in per channel, not the default). -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

type DeadLetterRow = {
  id: number
  channel: string
  subject: string
  recipient_email: string | null
  attempts: number
  last_error: string | null
  created_at: string
  resent_at: string | null
  discarded_at: string | null
}

defineProps<{
  deadLetters: DeadLetterRow[]
}>()

const columns = [
  { key: 'created_at', label: 'Dead-lettered', sortable: true },
  { key: 'channel', label: 'Channel' },
  { key: 'subject', label: 'Subject' },
  { key: 'recipient_email', label: 'Recipient' },
  { key: 'attempts', label: 'Attempts' },
  { key: 'last_error', label: 'Last error' },
  { key: 'state', label: 'State' },
  { key: 'actions', label: '' },
]

const stateOf = (row: DeadLetterRow) => (row.resent_at ? 'resent' : row.discarded_at ? 'discarded' : 'open')

const { confirm } = useConfirm()

const resend = (row: DeadLetterRow) => {
  confirm({
    title: 'Re-queue Delivery?',
    description: 'Re-queue this delivery with a fresh attempt counter?',
    confirmText: 'Re-queue',
    onConfirm: () => router.post(route('wne.dead-letters.resend', row.id), {}, { preserveScroll: true }),
  })
}

const discard = (row: DeadLetterRow) => {
  confirm({
    title: 'Discard Dead Letter?',
    description: 'Discard this dead letter? This is logged and cannot be undone.',
    variant: 'destructive',
    confirmText: 'Discard',
    onConfirm: () => router.post(route('wne.dead-letters.discard', row.id), {}, { preserveScroll: true }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Dead Letters" description="Deliveries that exhausted their channel's retry policy — resend once fixed, or discard." />

    <WneSubNav active="dead-letters" class="mt-6" />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="deadLetters"
        empty-title="No dead letters"
        empty-description="Nothing has exhausted a retry policy — either everything's delivering, or no channel has opted into multi-attempt retry yet."
      >
        <template #cell-recipient_email="{ item }">
          <span class="text-sm text-ink-600">{{ (item as DeadLetterRow).recipient_email ?? '—' }}</span>
        </template>
        <template #cell-last_error="{ item }">
          <span class="text-sm text-ink-600">{{ (item as DeadLetterRow).last_error ?? '—' }}</span>
        </template>
        <template #cell-state="{ item }">
          <StatusBadge :status="stateOf(item as DeadLetterRow)" />
        </template>
        <template #cell-actions="{ item }">
          <div v-if="stateOf(item as DeadLetterRow) === 'open'" class="flex gap-2">
            <button
              type="button"
              class="rounded-sm border border-border bg-surface-0 px-2.5 py-1 text-xs font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
              @click="resend(item as DeadLetterRow)"
            >
              Resend
            </button>
            <button
              type="button"
              class="rounded-sm border border-signal-danger/30 bg-surface-0 px-2.5 py-1 text-xs font-semibold text-signal-danger shadow-sm transition hover:bg-signal-danger/10"
              @click="discard(item as DeadLetterRow)"
            >
              Discard
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
