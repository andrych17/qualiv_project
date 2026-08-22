<!-- ponytail: Notary Protocol books (§3F) — ledger-of-ledgers overview -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface BookRow {
  id: number
  book_type: string
  year: number
  volume: number
  status: string
  notary_name: string | null
  entries_count: number
  opened_at: string | null
  closed_at: string | null
  handed_over_to: string | null
}

defineProps<{ books: BookRow[] }>()

const TYPE_LABEL: Record<string, string> = {
  repertorium: 'Repertorium',
  legalisasi: 'Buku Daftar Legalisasi',
  waarmerking: 'Buku Daftar Waarmerking',
  protes: 'Buku Daftar Protes',
  daftar_wasiat: 'Buku Daftar Wasiat',
  lain_lain: 'Lain-lain',
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Notary Protocol" description="The statutory record-of-records — sequential, append-only, handover-able.">
      <template #actions>
        <PrimaryButton :href="route('legal.protocolBooks.create')">Open book</PrimaryButton>
      </template>
    </PageHeader>

    <LegalSubNav active="protocol-books" class="mt-6" />

    <div class="mt-6 space-y-3">
      <Panel v-if="books.length === 0">
        <p class="text-sm text-ink-600">No protocol books opened yet.</p>
      </Panel>
      <Link
        v-for="b in books"
        :key="b.id"
        :href="route('legal.protocolBooks.show', b.id)"
        class="block rounded-md border border-border bg-surface-0 p-4 shadow-sm transition hover:bg-surface-50"
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-semibold text-ink-900">{{ TYPE_LABEL[b.book_type] ?? b.book_type }} — {{ b.year }}, Vol. {{ b.volume }}</p>
            <p class="mt-0.5 text-xs text-ink-600">
              {{ b.notary_name ?? 'Unassigned' }} · {{ b.entries_count }} {{ b.entries_count === 1 ? 'entry' : 'entries' }}
              <span v-if="b.handed_over_to"> · handed over to {{ b.handed_over_to }}</span>
            </p>
          </div>
          <StatusBadge :status="b.status" />
        </div>
      </Link>
    </div>
  </AppLayout>
</template>
