<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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

const columns = computed(() => [
  { key: 'created_at', label: t('wne.dead_letters'), sortable: true },
  { key: 'channel', label: t('wne.channel') },
  { key: 'subject', label: t('wne.subject') },
  { key: 'recipient_email', label: t('wne.recipient') },
  { key: 'attempts', label: t('wne.attempts') },
  { key: 'last_error', label: t('wne.last_error') },
  { key: 'state', label: t('wne.state') },
  { key: 'actions', label: t('common.actions') },
])

const stateOf = (row: DeadLetterRow) => (row.resent_at ? 'resent' : row.discarded_at ? 'discarded' : 'open')

const { confirm } = useConfirm()

const resend = (row: DeadLetterRow) => {
  confirm({
    title: t('wne.requeue_title'),
    description: t('wne.requeue_desc'),
    confirmText: t('wne.requeue'),
    onConfirm: () => router.post(route('wne.dead-letters.resend', row.id), {}, { preserveScroll: true }),
  })
}

const discard = (row: DeadLetterRow) => {
  confirm({
    title: t('wne.discard_title'),
    description: t('wne.discard_desc'),
    variant: 'destructive',
    confirmText: t('wne.discard'),
    onConfirm: () => router.post(route('wne.dead-letters.discard', row.id), {}, { preserveScroll: true }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('wne.dead_letters')" :description="t('wne.dead_letters_desc')" />

    <WneSubNav active="dead-letters" class="mt-6" />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="deadLetters"
        :empty-title="t('wne.empty_dead_letters_title')"
        :empty-description="t('wne.empty_dead_letters_desc')"
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
              class="rounded-sm border border-border bg-surface-0 px-2.5 py-1 text-xs font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 cursor-pointer"
              @click="resend(item as DeadLetterRow)"
            >
              {{ t('wne.resend') }}
            </button>
            <button
              type="button"
              class="rounded-sm border border-signal-danger/30 bg-surface-0 px-2.5 py-1 text-xs font-semibold text-signal-danger shadow-sm transition hover:bg-signal-danger/10 cursor-pointer"
              @click="discard(item as DeadLetterRow)"
            >
              {{ t('wne.discard') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
