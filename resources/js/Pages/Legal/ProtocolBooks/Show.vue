<!-- ponytail: Protocol book detail (§3F) — entries ledger + close/handover lifecycle -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface EntryRow {
  id: number
  sequence_number: number
  entry_date: string
  deed_number: string | null
  deed_id: number | null
}

const props = defineProps<{
  book: {
    id: number
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

const { confirm } = useConfirm()

const confirmClose = () => {
  confirm({
    title: 'Close this book?',
    description: 'No further entries can be recorded once closed.',
    confirmText: 'Close book',
    onConfirm: () => router.patch(route('legal.protocolBooks.close', props.book.id)),
  })
}

const showHandoverForm = ref(false)
const handoverForm = useForm({ recipient: '' })
const submitHandover = () => {
  handoverForm.patch(route('legal.protocolBooks.handover', props.book.id), {
    onSuccess: () => { showHandoverForm.value = false },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="`${TYPE_LABEL[book.book_type] ?? book.book_type} — ${book.year}, Vol. ${book.volume}`" :description="`Notary: ${book.notary_name ?? 'Unassigned'}`">
      <template #actions>
        <StatusBadge :status="book.status" />
      </template>
    </PageHeader>

    <div class="mt-6 grid max-w-3xl gap-6 lg:grid-cols-[1fr_260px]">
      <Panel>
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-600">Entries</p>
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-ink-600">
              <th class="pb-2">#</th>
              <th class="pb-2">Date</th>
              <th class="pb-2">Deed</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in entries" :key="e.id" class="border-b border-border/50">
              <td class="py-1.5 font-mono">{{ e.sequence_number }}</td>
              <td class="py-1.5">{{ e.entry_date }}</td>
              <td class="py-1.5">
                <Link v-if="e.deed_id" :href="route('legal.deeds.edit', e.deed_id)" class="text-accent hover:underline">
                  {{ e.deed_number || `#${e.deed_id}` }}
                </Link>
                <span v-else>—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="entries.length === 0" class="text-sm text-ink-600">No entries recorded yet.</p>
      </Panel>

      <Panel class="h-fit space-y-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Lifecycle</p>

        <a
          :href="route('legal.protocolBooks.manifest', book.id)"
          target="_blank"
          class="block w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-center text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
        >
          View manifest
        </a>

        <button
          v-if="book.status === 'active'"
          type="button"
          class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
          @click="confirmClose"
        >
          Close book
        </button>

        <template v-if="book.status !== 'handed_over'">
          <button
            v-if="!showHandoverForm"
            type="button"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50"
            @click="showHandoverForm = true"
          >
            Hand over
          </button>
          <form v-else class="space-y-2" @submit.prevent="submitHandover">
            <FormInput
              v-model="handoverForm.recipient"
              name="recipient"
              label="Recipient (successor notary or MPD)"
              :error="handoverForm.errors.recipient"
              required
            />
            <PrimaryButton type="submit" class="w-full" :disabled="handoverForm.processing">Confirm handover</PrimaryButton>
          </form>
        </template>

        <p v-if="book.handed_over_to" class="text-xs text-ink-600">
          Handed over to {{ book.handed_over_to }} on {{ book.handed_over_at }}.
        </p>
      </Panel>
    </div>
  </AppLayout>
</template>
