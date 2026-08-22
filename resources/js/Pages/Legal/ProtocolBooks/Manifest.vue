<!-- ponytail: Handover manifest (§3F) — standalone printable page, no AppLayout chrome.
     Browser print-to-PDF for now; swap for a real PDF lib (dompdf) if volume ever justifies it. -->
<script setup lang="ts">
interface EntryRow {
  sequence_number: number
  entry_date: string
  deed_number: string | null
  minuta_reference: string | null
}

defineProps<{
  book: {
    book_type: string
    year: number
    volume: number
    status: string
    notary_name: string | null
    opened_at: string | null
    closed_at: string | null
    handed_over_to: string | null
    handed_over_at: string | null
  }
  entries: EntryRow[]
}>()

const TYPE_LABEL: Record<string, string> = {
  repertorium: 'Repertorium',
  legalisasi: 'Buku Daftar Legalisasi',
  waarmerking: 'Buku Daftar Waarmerking',
  protes: 'Buku Daftar Protes',
  daftar_wasiat: 'Buku Daftar Wasiat',
  lain_lain: 'Lain-lain',
}

const printPage = () => window.print()
</script>

<template>
  <div class="mx-auto max-w-3xl p-8 text-ink-900">
    <div class="mb-2 flex items-center justify-between print:hidden">
      <p class="text-sm text-ink-600">Print or save as PDF using your browser's print dialog.</p>
      <button
        type="button"
        class="rounded-sm border border-border bg-surface-0 px-3 py-1.5 text-sm font-semibold shadow-sm hover:bg-surface-50"
        @click="printPage"
      >
        Print
      </button>
    </div>

    <h1 class="text-xl font-bold">Protocol Book Manifest</h1>
    <p class="mt-1 text-sm text-ink-600">{{ TYPE_LABEL[book.book_type] ?? book.book_type }} — {{ book.year }}, Volume {{ book.volume }}</p>

    <dl class="mt-6 grid grid-cols-2 gap-4 text-sm">
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Notary</dt>
        <dd>{{ book.notary_name ?? '—' }}</dd>
      </div>
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Status</dt>
        <dd class="capitalize">{{ book.status.replace('_', ' ') }}</dd>
      </div>
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Opened</dt>
        <dd>{{ book.opened_at || '—' }}</dd>
      </div>
      <div>
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Closed</dt>
        <dd>{{ book.closed_at || '—' }}</dd>
      </div>
      <div class="col-span-2" v-if="book.handed_over_to">
        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">Handed over to</dt>
        <dd>{{ book.handed_over_to }} on {{ book.handed_over_at }}</dd>
      </div>
    </dl>

    <table class="mt-6 w-full border-collapse text-sm">
      <thead>
        <tr class="border-b-2 border-ink-900 text-left">
          <th class="py-1.5 pr-2">Seq.</th>
          <th class="py-1.5 pr-2">Date</th>
          <th class="py-1.5 pr-2">Deed no.</th>
          <th class="py-1.5">Minuta reference</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="e in entries" :key="e.sequence_number" class="border-b border-border">
          <td class="py-1 pr-2 font-mono">{{ e.sequence_number }}</td>
          <td class="py-1 pr-2">{{ e.entry_date }}</td>
          <td class="py-1 pr-2">{{ e.deed_number || '—' }}</td>
          <td class="py-1">{{ e.minuta_reference || '—' }}</td>
        </tr>
      </tbody>
    </table>
    <p v-if="entries.length === 0" class="mt-4 text-sm text-ink-600">No entries recorded.</p>
  </div>
</template>
