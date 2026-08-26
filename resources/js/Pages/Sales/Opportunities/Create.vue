<!-- Create Opportunity (§3C) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  stages: string[]
  customers: Array<{ id: number; name: string }>
  leads: Array<{ id: number; title: string }>
  users: Array<{ id: number; name: string }>
  teams: Array<{ id: number; name: string }>
}>()

const form = useForm({
  name: '',
  customer_id: null as number | null,
  lead_id: null as number | null,
  stage: 'new',
  owner_id: null as number | null,
  sales_team_id: null as number | null,
  estimated_value: null as number | null,
  expected_close_date: null as string | null,
  loss_reason: null as string | null,
})

const submit = () => {
  form.post(route('sales.opportunities.store'))
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Create Opportunity"
      description="Track a new sales opportunity in the deal pipeline."
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
                placeholder="e.g. Retainer Agreement 2026 - Acme Corp"
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
                placeholder="e.g. 50000000"
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
                placeholder="Assign representative…"
              />
            </div>

            <div>
              <FormSelect
                label="Sales Team"
                v-model="form.sales_team_id"
                :error="form.errors.sales_team_id"
                :options="props.teams.map(t => ({ value: t.id, label: t.name }))"
                placeholder="Assign sales team…"
              />
            </div>

            <div v-if="form.stage === 'lost'" class="sm:col-span-2">
              <FormInput
                label="Loss Reason *"
                v-model="form.loss_reason"
                :error="form.errors.loss_reason"
                placeholder="e.g. Competitor pricing, delayed budget"
                required
              />
            </div>
          </div>
        </Panel>

        <div class="flex items-center justify-end gap-3">
          <SecondaryButton :href="route('sales.opportunities.index')">Cancel</SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Opportunity</PrimaryButton>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
