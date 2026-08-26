<!-- ponytail: Accounting §3B/§3I cost centers — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  parents: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  name: '',
  parent_cost_center_id: null as number | null,
})

const companyOptions = props.companies.map((c) => ({ value: c.id, label: c.legal_name }))

const submit = () => form.post(route('accounting.cost-centers.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Cost Center" description="Segment managerial accounting and departmental cost allocation." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companyOptions" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Cost Center Code" placeholder="e.g. CC-CORP-01" :error="form.errors.code" required />
        <FormInput v-model="form.name" name="name" label="Cost Center Name" placeholder="e.g. Corporate Management" :error="form.errors.name" required />
        <FormSearchableSelect v-model="form.parent_cost_center_id" name="parent_cost_center_id" label="Parent Cost Center" placeholder="No parent (top-level)" :options="parents" :error="form.errors.parent_cost_center_id" />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.cost-centers.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Cost Center</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
