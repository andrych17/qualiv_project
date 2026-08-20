<!-- ponytail: Edit Lead (§3D) — form + stage status + Convert/Disqualify actions + activity log -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import LeadActivityLog, { type LeadActivityRow } from '@/Components/crm/LeadActivityLog.vue'
import ConvertLeadModal from '@/Components/crm/ConvertLeadModal.vue'
import DisqualifyLeadModal from '@/Components/crm/DisqualifyLeadModal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  lead: {
    id: number
    name: string
    company_name: string | null
    source_id: number | null
    stage: string
    owner_id: number | null
    estimated_value: string | number | null
    next_action_at: string | null
    notes: string | null
    disqualify_reason: string | null
    converted_partner: { id: number; name: string; type: string } | null
    activities: LeadActivityRow[]
  }
  sources: Array<{ id: number; name: string }>
  owners: Array<{ id: number; name: string }>
  roleTypes: Array<{ id: number; name: string }>
  customFields: CustomFieldDef[]
}>()

const customBag: Record<string, string> = {}
for (const f of props.customFields) {
  customBag[f.code] = f.value ?? ''
}

const form = useForm({
  name: props.lead.name,
  company_name: props.lead.company_name ?? '',
  source_id: props.lead.source_id,
  owner_id: props.lead.owner_id,
  estimated_value: props.lead.estimated_value ?? '',
  next_action_at: props.lead.next_action_at ?? '',
  notes: props.lead.notes ?? '',
  custom_fields: customBag,
})

const submit = () => form.put(route('crm.leads.update', props.lead.id))

const isOpen = props.lead.stage !== 'converted' && props.lead.stage !== 'disqualified'
const showConvert = ref(false)
const showDisqualify = ref(false)

const convertedPartnerHref = () => {
  if (!props.lead.converted_partner) return '#'
  return props.lead.converted_partner.type === 'organization'
    ? route('crm.companies.edit', props.lead.converted_partner.id)
    : route('crm.contacts.edit', props.lead.converted_partner.id)
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="lead.name" :description="lead.company_name ?? 'No company'">
      <template #actions>
        <StatusBadge :status="lead.stage" />
      </template>
    </PageHeader>

    <CrmSubNav active="leads" class="mt-6" />

    <div class="mt-6 grid max-w-4xl grid-cols-1 gap-6 lg:grid-cols-3">
      <Panel class="lg:col-span-2">
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
            <PrimaryButton type="submit" :disabled="form.processing">Update lead</PrimaryButton>
          </div>
        </form>
      </Panel>

      <div class="space-y-6">
        <Panel title="Pipeline">
          <div v-if="lead.converted_partner" class="text-sm">
            <p class="text-ink-600">Converted to:</p>
            <Link :href="convertedPartnerHref()" class="font-medium text-accent hover:underline">
              {{ lead.converted_partner.name }}
            </Link>
          </div>
          <div v-else-if="lead.stage === 'disqualified'" class="text-sm">
            <p class="text-ink-600">Disqualify reason:</p>
            <p class="font-medium text-ink-900">{{ lead.disqualify_reason }}</p>
          </div>
          <div v-else class="space-y-2">
            <button
              type="button"
              class="w-full rounded-md border border-signal-success/40 bg-signal-success/5 px-3 py-2 text-sm font-semibold text-signal-success hover:bg-signal-success/10"
              @click="showConvert = true"
            >
              Convert to Partner
            </button>
            <button
              type="button"
              class="w-full rounded-md border border-signal-danger/40 bg-signal-danger/5 px-3 py-2 text-sm font-semibold text-signal-danger hover:bg-signal-danger/10"
              @click="showDisqualify = true"
            >
              Disqualify
            </button>
          </div>
        </Panel>

        <Panel title="Activity">
          <LeadActivityLog :lead-id="lead.id" :activities="lead.activities" />
        </Panel>
      </div>
    </div>

    <ConvertLeadModal
      :show="showConvert"
      :lead-id="lead.id"
      :lead-name="lead.name"
      :has-company-name="!!lead.company_name"
      :role-types="roleTypes"
      @close="showConvert = false"
    />
    <DisqualifyLeadModal
      :show="showDisqualify"
      :lead-id="lead.id"
      :lead-name="lead.name"
      @close="showDisqualify = false"
    />
  </AppLayout>
</template>
