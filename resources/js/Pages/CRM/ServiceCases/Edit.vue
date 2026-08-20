<!-- ponytail: Edit Service Case (§3E) — form + status control + SLA badge + activity log -->
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
import ServiceCaseActivityLog, { type ServiceCaseActivityRow } from '@/Components/crm/ServiceCaseActivityLog.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

// prop named "serviceCase", not "case" — `case` is a reserved JS word and breaks
// template expressions like `case.subject` even though `props.case` compiles fine.
const props = defineProps<{
  serviceCase: {
    id: number
    partner_id: number
    partner: { value: number; label: string } | null
    subject: string
    category_id: number | null
    priority: string
    status: string
    assigned_to: number | null
    sla_due_at: string | null
    subject_type: string | null
    subject_id: string | null
    sla_state: string
    can_reopen: boolean
    closed_at_formatted: string | null
    activities: ServiceCaseActivityRow[]
  }
  categories: Array<{ id: number; name: string }>
  assignees: Array<{ id: number; name: string }>
}>()

const form = useForm({
  partner_id: props.serviceCase.partner_id,
  subject: props.serviceCase.subject,
  category_id: props.serviceCase.category_id,
  priority: props.serviceCase.priority,
  assigned_to: props.serviceCase.assigned_to,
  sla_due_at: props.serviceCase.sla_due_at ?? '',
  subject_type: props.serviceCase.subject_type ?? '',
  subject_id: props.serviceCase.subject_id ?? '',
})

const submit = () => form.put(route('crm.serviceCases.update', props.serviceCase.id))

const STATUS_OPTIONS = [
  { label: 'Open', value: 'open' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Waiting on partner', value: 'waiting_on_partner' },
  { label: 'Resolved', value: 'resolved' },
  { label: 'Closed', value: 'closed' },
]

const statusForm = useForm({ status: props.serviceCase.status })
const isReopenAttempt = (next: string) => props.serviceCase.status === 'closed' && next !== 'closed'
const updateStatus = () => {
  statusForm.patch(route('crm.serviceCases.updateStatus', props.serviceCase.id), { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="serviceCase.subject" :description="serviceCase.partner?.label ?? ''">
      <template #actions>
        <StatusBadge :status="serviceCase.status" />
        <StatusBadge :status="serviceCase.sla_state" :label="serviceCase.sla_state.replace('_', ' ')" />
      </template>
    </PageHeader>

    <CrmSubNav active="service-cases" class="mt-6" />

    <div class="mt-6 grid max-w-4xl grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-2">
        <form class="space-y-4" @submit.prevent="submit">
          <FormAsyncSearchableSelect
            v-model="form.partner_id"
            name="partner_id"
            label="Partner"
            api-entity="crm_partner"
            placeholder="Search for a contact or company…"
            required
            :error="form.errors.partner_id"
          />
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
          <div class="grid grid-cols-2 gap-4">
            <FormInput
              v-model="form.subject_type"
              name="subject_type"
              label="Related record type"
              placeholder="e.g. legal.case_hdrs"
              :error="form.errors.subject_type"
            />
            <FormInput
              v-model="form.subject_id"
              name="subject_id"
              label="Related record ID"
              placeholder="e.g. 4821"
              :error="form.errors.subject_id"
            />
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
            <Link
              :href="route('crm.serviceCases.index')"
              class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Cancel
            </Link>
            <PrimaryButton type="submit" :disabled="form.processing">Save case</PrimaryButton>
          </div>
        </form>
      </Panel>

      <div class="space-y-6">
        <Panel title="Status">
          <div class="space-y-2">
            <FormSelect
              v-model="statusForm.status"
              name="status"
              label="Case status"
              :options="STATUS_OPTIONS"
              :error="statusForm.errors.status"
            />
            <p
              v-if="isReopenAttempt(statusForm.status) && serviceCase.can_reopen"
              class="text-xs text-signal-warning"
            >
              Closed {{ serviceCase.closed_at_formatted }} — still within the 7-day reopen window.
            </p>
            <p
              v-else-if="isReopenAttempt(statusForm.status)"
              class="text-xs text-signal-danger"
            >
              Closed {{ serviceCase.closed_at_formatted }} — past the 7-day reopen window. Open a new case instead.
            </p>
            <button
              type="button"
              class="w-full rounded-md border border-accent/40 bg-accent/5 px-3 py-2 text-sm font-semibold text-accent hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="statusForm.processing || statusForm.status === serviceCase.status"
              @click="updateStatus"
            >
              Update status
            </button>
          </div>
        </Panel>

        <Panel title="Activity">
          <ServiceCaseActivityLog :case-id="serviceCase.id" :activities="serviceCase.activities" />
        </Panel>
      </div>
    </div>
  </AppLayout>
</template>
