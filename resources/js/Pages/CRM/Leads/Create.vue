<!-- ponytail: Create Lead (§3D) — mirrors Contacts/Companies Create -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  sources: Array<{ id: number; name: string }>
  owners: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  name: '',
  company_name: '',
  source_id: null as number | null,
  owner_id: null as number | null,
  estimated_value: '',
  next_action_at: '',
  notes: '',
  custom_fields: customBag,
})

const submit = () => form.post(route('crm.leads.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Add lead" description="Track pre-partner interest before it's ready to convert." />

    <CrmSubNav active="leads" class="mt-6" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput v-model="form.company_name" name="company_name" label="Company" :error="form.errors.company_name" />
        <FormSelect
          v-model="form.source_id"
          name="source_id"
          label="Source"
          placeholder="Unknown"
          :options="sources.map((s) => ({ label: s.name, value: s.id }))"
          :error="form.errors.source_id"
        />
        <FormSelect
          v-model="form.owner_id"
          name="owner_id"
          label="Owner"
          placeholder="Unassigned"
          :options="owners.map((o) => ({ label: o.name, value: o.id }))"
          :error="form.errors.owner_id"
        />
        <FormInput
          v-model="form.estimated_value"
          name="estimated_value"
          type="number"
          label="Estimated value"
          placeholder="Optional — free-form, no currency logic here"
          :error="form.errors.estimated_value"
        />
        <FormInput
          v-model="form.next_action_at"
          name="next_action_at"
          type="datetime-local"
          label="Next action"
          :error="form.errors.next_action_at"
        />
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
            :href="route('crm.leads.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save lead</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
