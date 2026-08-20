<!-- ponytail: Edit Ticket (§3F) — form + status control + relink-to-partner + message thread -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import TicketMessageThread, { type TicketMessageRow } from '@/Components/crm/TicketMessageThread.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  ticket: {
    id: number
    partner_id: number | null
    partner: { value: number; label: string } | null
    requester_name: string | null
    requester_contact: string | null
    subject: string
    category_id: number | null
    priority: string
    status: string
    assigned_to: number | null
    sla_due_at: string | null
    channel: string
    sla_state: string
    messages: TicketMessageRow[]
  }
  categories: Array<{ id: number; name: string }>
  assignees: Array<{ id: number; name: string }>
}>()

const form = useForm({
  partner_id: props.ticket.partner_id,
  requester_name: props.ticket.requester_name ?? '',
  requester_contact: props.ticket.requester_contact ?? '',
  subject: props.ticket.subject,
  category_id: props.ticket.category_id,
  priority: props.ticket.priority,
  assigned_to: props.ticket.assigned_to,
  sla_due_at: props.ticket.sla_due_at ?? '',
  channel: props.ticket.channel,
})

const submit = () => form.put(route('crm.tickets.update', props.ticket.id))

const STATUS_OPTIONS = [
  { label: 'Open', value: 'open' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Waiting on partner', value: 'waiting_on_partner' },
  { label: 'Resolved', value: 'resolved' },
  { label: 'Closed', value: 'closed' },
]

const statusForm = useForm({ status: props.ticket.status })
const updateStatus = () => {
  statusForm.patch(route('crm.tickets.updateStatus', props.ticket.id), { preserveScroll: true })
}

const relinkForm = useForm({ partner_id: null as number | null })
const relink = () => {
  if (!relinkForm.partner_id) return
  relinkForm.post(route('crm.tickets.relink', props.ticket.id), { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="ticket.subject" :description="ticket.partner?.label ?? ticket.requester_name ?? 'Unknown requester'">
      <template #actions>
        <StatusBadge :status="ticket.status" />
        <StatusBadge :status="ticket.sla_state" :label="ticket.sla_state.replace('_', ' ')" />
      </template>
    </PageHeader>

    <CrmSubNav active="tickets" class="mt-6" />

    <div class="mt-6 grid max-w-4xl grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="submit">
          <FormAsyncSearchableSelect
            v-model="form.partner_id"
            name="partner_id"
            label="Partner (if known)"
            api-entity="crm_partner"
            placeholder="Search for a contact or company…"
            :error="form.errors.partner_id"
          />
          <div class="grid grid-cols-2 gap-4">
            <FormInput
              v-model="form.requester_name"
              name="requester_name"
              label="Requester name"
              :error="form.errors.requester_name"
            />
            <FormInput
              v-model="form.requester_contact"
              name="requester_contact"
              label="Requester contact"
              :error="form.errors.requester_contact"
            />
          </div>
          <FormInput v-model="form.subject" name="subject" label="Subject" :error="form.errors.subject" required />
          <FormSelect
            v-model="form.category_id"
            name="category_id"
            label="Category"
            placeholder="Uncategorized"
            :options="categories.map((c) => ({ label: c.name, value: c.id }))"
            :error="form.errors.category_id"
          />
          <FormSelect
            v-model="form.priority"
            name="priority"
            label="Priority"
            :options="[
              { label: 'Low', value: 'low' },
              { label: 'Normal', value: 'normal' },
              { label: 'High', value: 'high' },
              { label: 'Urgent', value: 'urgent' },
            ]"
            :error="form.errors.priority"
          />
          <FormSelect
            v-model="form.channel"
            name="channel"
            label="Channel"
            :options="[
              { label: 'Email', value: 'email' },
              { label: 'Phone', value: 'phone' },
              { label: 'Web form', value: 'web_form' },
              { label: 'In-app', value: 'in_app' },
            ]"
            :error="form.errors.channel"
          />
          <FormSelect
            v-model="form.assigned_to"
            name="assigned_to"
            label="Assigned to"
            placeholder="Unassigned"
            :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
            :error="form.errors.assigned_to"
          />
          <FormInput
            v-model="form.sla_due_at"
            name="sla_due_at"
            type="datetime-local"
            label="SLA due"
            :error="form.errors.sla_due_at"
          />

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <Link
              :href="route('crm.tickets.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Cancel
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save ticket</PrimaryButton>
          </div>
        </form>
      </Panel>

      <div class="space-y-6">
        <Panel title="Status">
          <div class="space-y-2">
            <FormSelect
              v-model="statusForm.status"
              name="status"
              label="Ticket status"
              :options="STATUS_OPTIONS"
              :error="statusForm.errors.status"
            />
            <button
              type="button"
              class="w-full rounded-md border border-accent/40 bg-accent/5 px-3 py-2 text-sm font-semibold text-accent hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="statusForm.processing || statusForm.status === ticket.status"
              @click="updateStatus"
            >
              Update status
            </button>
          </div>
        </Panel>

        <Panel v-if="!ticket.partner" title="Identify requester">
          <div class="space-y-2">
            <p class="text-xs text-ink-600">§3F: link this ticket to a partner without losing the message thread.</p>
            <FormAsyncSearchableSelect
              v-model="relinkForm.partner_id"
              name="relink_partner_id"
              api-entity="crm_partner"
              placeholder="Search for a contact or company…"
              :error="relinkForm.errors.partner_id"
            />
            <button
              type="button"
              class="w-full rounded-md border border-accent/40 bg-accent/5 px-3 py-2 text-sm font-semibold text-accent hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="relinkForm.processing || !relinkForm.partner_id"
              @click="relink"
            >
              Link to partner
            </button>
          </div>
        </Panel>

        <Panel title="Messages">
          <TicketMessageThread :ticket-id="ticket.id" :messages="ticket.messages" />
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
