<!-- Edit Opportunity (§3C) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

interface OpportunityItem {
  id: number
  name: string
  customer_id: number | null
  lead_id: number | null
  stage: string
  owner_id: number | null
  sales_team_id: number | null
  estimated_value: number | null
  expected_close_date: string | null
  loss_reason: string | null
}

const props = defineProps<{
  opportunity: OpportunityItem
  stages: string[]
  customers: Array<{ id: number; name: string }>
  leads: Array<{ id: number; title: string }>
  users: Array<{ id: number; name: string }>
  teams: Array<{ id: number; name: string }>
}>()

const form = useForm({
  name: props.opportunity.name,
  customer_id: props.opportunity.customer_id,
  lead_id: props.opportunity.lead_id,
  stage: props.opportunity.stage,
  owner_id: props.opportunity.owner_id,
  sales_team_id: props.opportunity.sales_team_id,
  estimated_value: props.opportunity.estimated_value,
  expected_close_date: props.opportunity.expected_close_date,
  loss_reason: props.opportunity.loss_reason,
})

const submit = () => {
  form.put(route('sales.opportunities.update', props.opportunity.id))
}

const deleteOpportunity = () => {
  if (confirm('Are you sure you want to delete this opportunity?')) {
    form.delete(route('sales.opportunities.destroy', props.opportunity.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Edit Opportunity"
      :description="`Update pipeline status for ${props.opportunity.name}`"
    />

    <div class="mt-6 max-w-3xl">
      <form @submit.prevent="submit" class="space-y-6">
        <Panel title="Opportunity Details">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <FormInput
                label="Opportunity Name *"
                v-model="form.name"
                :error="form.errors.name"
                required
              />
            </div>

            <div>
              <FormSelect
                label="Customer / Client"
                v-model="form.customer_id"
                :error="form.errors.customer_id"
                :options="props.customers.map(c => ({ value: c.id, label: c.name }))"
                placeholder="Select existing customer…"
              />
            </div>

            <div>
              <FormSelect
                label="Originating CRM Lead"
                v-model="form.lead_id"
                :error="form.errors.lead_id"
                :options="props.leads.map(l => ({ value: l.id, label: l.title }))"
                placeholder="Select lead (optional)…"
              />
            </div>

            <div>
              <FormSelect
                label="Pipeline Stage *"
                v-model="form.stage"
                :error="form.errors.stage"
                :options="props.stages.map(s => ({ value: s, label: s.toUpperCase() }))"
                required
              />
            </div>

            <div>
              <FormInput
                label="Estimated Deal Value (IDR)"
                type="number"
                v-model="form.estimated_value"
                :error="form.errors.estimated_value"
              />
            </div>

            <div>
              <FormInput
                label="Expected Close Date"
                type="date"
                v-model="form.expected_close_date"
                :error="form.errors.expected_close_date"
              />
            </div>

            <div>
              <FormSelect
                label="Sales Owner"
                v-model="form.owner_id"
                :error="form.errors.owner_id"
                :options="props.users.map(u => ({ value: u.id, label: u.name }))"
              />
            </div>

            <div>
              <FormSelect
                label="Sales Team"
                v-model="form.sales_team_id"
                :error="form.errors.sales_team_id"
                :options="props.teams.map(t => ({ value: t.id, label: t.name }))"
              />
            </div>

            <div v-if="form.stage === 'lost'" class="sm:col-span-2">
              <FormInput
                label="Loss Reason *"
                v-model="form.loss_reason"
                :error="form.errors.loss_reason"
                placeholder="e.g. Competitor pricing, budget cancelled"
                required
              />
            </div>
          </div>
        </Panel>

        <div class="flex items-center justify-between">
          <DangerButton type="button" @click="deleteOpportunity">Delete</DangerButton>
          <div class="flex items-center gap-3">
            <SecondaryButton :href="route('sales.opportunities.index')">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
