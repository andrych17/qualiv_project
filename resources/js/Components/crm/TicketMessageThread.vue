<!-- ponytail: hd_ticket_messages thread (§3F) — conversation-first, unlike ServiceCase's
     internal work log: inbound (requester) / outbound (staff reply) / internal_note. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

export interface TicketMessageRow {
  id: number
  direction: string
  body: string
  sender_name: string | null
  sent_at_formatted: string | null
}

const props = defineProps<{
  ticketId: number
  messages: TicketMessageRow[]
}>()

const form = useForm({ direction: 'outbound', body: '' })

const submit = () => {
  form.post(route('crm.tickets.messages.store', props.ticketId), {
    preserveScroll: true,
    onSuccess: () => form.reset('body'),
  })
}

const DIRECTION_LABEL: Record<string, string> = {
  inbound: 'Requester',
  outbound: 'Reply',
  internal_note: 'Internal note',
}
</script>

<template>
  <div class="space-y-4">
    <ul v-if="messages.length" class="space-y-3">
      <li
        v-for="m in messages"
        :key="m.id"
        class="rounded-md border px-3 py-2"
        :class="{
          'border-border bg-surface-0': m.direction === 'inbound',
          'border-accent/30 bg-accent/5': m.direction === 'outbound',
          'border-border bg-surface-50': m.direction === 'internal_note',
        }"
      >
        <p class="text-xs font-medium uppercase tracking-wide text-ink-600">
          {{ DIRECTION_LABEL[m.direction] ?? m.direction }}
          <span class="font-normal normal-case text-ink-600/70">
            — {{ m.sender_name ?? 'Unknown' }} · {{ m.sent_at_formatted }}
          </span>
        </p>
        <p class="mt-0.5 whitespace-pre-line text-sm text-ink-900">{{ m.body }}</p>
      </li>
    </ul>
    <div v-else class="text-sm text-ink-600">No messages yet.</div>

    <form class="space-y-2 border-t border-border pt-4" @submit.prevent="submit">
      <div class="flex items-end gap-2">
        <div class="w-40">
          <FormSelect
            v-model="form.direction"
            name="direction"
            label="Send as"
            :options="[
              { label: 'Reply', value: 'outbound' },
              { label: 'Internal note', value: 'internal_note' },
            ]"
          />
        </div>
        <div class="flex-1">
          <textarea
            v-model="form.body"
            rows="2"
            placeholder="Write a reply or internal note…"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
        <PrimaryButton type="submit" :disabled="form.processing || !form.body">Send</PrimaryButton>
      </div>
    </form>
  </div>
</template>
