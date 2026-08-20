<!-- ponytail: Create Service Case (§3E) — mirrors Contacts/Leads Create -->
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
  subject: '',
  category_id: null as number | null,
  priority: 'normal',
  assigned_to: null as number | null,
  sla_due_at: '',
  subject_type: '',
  subject_id: '',
})

const submit = () => form.post(route('crm.serviceCases.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Open service case" description="A support case tied to a partner." />

    <CrmSubNav active="service-cases" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
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
          <PrimaryButton type="submit" :disabled="form.processing">Open case</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
