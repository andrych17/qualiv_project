<!-- ponytail: Edit matter (§3B) — Panel + design buttons -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormTextarea from '@/Components/forms/FormTextarea.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  matter: {
    id: number
    code: string
    title: string
    matter_type: string | null
    partner_id: number | null
    assigned_to: number | null
    status: string
    opened_at: string | null
    target_close_at: string | null
    converted_from_lead_id: number | null
    notes: string | null
  }
  assignees: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  code: props.matter.code,
  title: props.matter.title,
  matter_type: props.matter.matter_type ?? '',
  partner_id: props.matter.partner_id,
  assigned_to: props.matter.assigned_to,
  status: props.matter.status,
  opened_at: props.matter.opened_at ?? '',
  target_close_at: props.matter.target_close_at ?? '',
  notes: props.matter.notes ?? '',
  custom_fields: customBag,
})

const submit = () => form.put(route('legal.matters.update', props.matter.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit matter" :description="matter.code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.code" name="code" label="Matter code" :error="form.errors.code" required />
        <FormInput v-model="form.title" name="title" label="Title" :error="form.errors.title" required />
        <FormInput
          v-model="form.matter_type"
          name="matter_type"
          label="Matter type"
          placeholder="e.g. Property Purchase, Company Incorporation"
          :error="form.errors.matter_type"
        />
        <FormAsyncSearchableSelect
          v-model="form.partner_id"
          name="partner_id"
          label="Primary client"
          api-entity="crm_partner"
          placeholder="Search for a contact or company…"
          :error="form.errors.partner_id"
        />
        <FormSelect
          v-model="form.assigned_to"
          name="assigned_to"
          label="Assigned notary/PPAT"
          placeholder="Unassigned"
          :options="assignees.map((a) => ({ label: a.name, value: a.id }))"
          :error="form.errors.assigned_to"
        />
        <FormSelect
          v-model="form.status"
          name="status"
          label="Status"
          :options="[
            { label: 'Open', value: 'open' },
            { label: 'In progress', value: 'in_progress' },
            { label: 'On hold', value: 'on_hold' },
            { label: 'Closed', value: 'closed' },
          ]"
          :error="form.errors.status"
          required
        />
        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.opened_at" name="opened_at" type="date" label="Opened" :error="form.errors.opened_at" />
          <FormInput v-model="form.target_close_at" name="target_close_at" type="date" label="Target close" :error="form.errors.target_close_at" />
        </div>
        <p v-if="matter.converted_from_lead_id" class="text-xs text-ink-600">
          Converted from CRM lead #{{ matter.converted_from_lead_id }}.
        </p>
        <FormTextarea
          v-model="form.notes"
          name="notes"
          label="Notes"
          :rows="3"
          :error="form.errors.notes"
        />
        <CustomFieldInputs
          v-model="form.custom_fields"
          :fields="customFields"
          :errors="form.errors"
        />
        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('legal.matters.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Update matter</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
