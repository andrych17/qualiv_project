<!-- ponytail: Create Ticket (§3F) — partner OR free-text requester, plus the initial message -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

defineProps<{
  categories: Array<{ id: number; name: string }>
  assignees: Array<{ id: number; name: string }>
}>()

const form = useForm({
  partner_id: null as number | null,
  requester_name: '',
  requester_contact: '',
  subject: '',
  message: '',
  category_id: null as number | null,
  priority: 'normal',
  assigned_to: null as number | null,
  sla_due_at: '',
  channel: 'email',
})

const submit = () => form.post(route('crm.tickets.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Open ticket" description="Support requests, inquiries, and complaints — with or without a known partner." />

    <CrmSubNav active="tickets" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
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
            placeholder="If no partner selected"
            :error="form.errors.requester_name"
          />
          <FormInput
            v-model="form.requester_contact"
            name="requester_contact"
            label="Requester contact"
            placeholder="Email or phone"
            :error="form.errors.requester_contact"
          />
        </div>
        <FormInput v-model="form.subject" name="subject" label="Subject" :error="form.errors.subject" required />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">
            Message <span class="text-signal-danger">*</span>
          </label>
          <textarea
            v-model="form.message"
            rows="4"
            placeholder="What did the requester say?"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
          <p v-if="form.errors.message" class="text-sm text-signal-danger">{{ form.errors.message }}</p>
        </div>
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
          <PrimaryButton type="submit" :disabled="form.processing">Open ticket</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
