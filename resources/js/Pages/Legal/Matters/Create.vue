<!-- ponytail: Create matter (§3B) — Panel + Primary/SecondaryButton -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  assignees: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  code: '',
  title: '',
  matter_type: '',
  partner_id: null as number | null,
  assigned_to: null as number | null,
  status: 'open',
  opened_at: '',
  target_close_at: '',
  notes: '',
  custom_fields: customBag,
})

const submit = () => form.post(route('legal.matters.store'))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Open matter"
      description="Register a new client engagement. Leave code blank to allocate from Serials."
    />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput
          v-model="form.code"
          name="code"
          label="Matter code"
          placeholder="Leave blank to auto-generate"
          :error="form.errors.code"
        />
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
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Notes</label>
          <textarea
            v-model="form.notes"
            rows="3"
            class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          />
        </div>
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
          <PrimaryButton type="submit" :disabled="form.processing">Save matter</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
