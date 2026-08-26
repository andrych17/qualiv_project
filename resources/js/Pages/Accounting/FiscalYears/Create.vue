<!-- ponytail: Accounting §3B fiscal calendar — create form; server auto-generates 12 monthly periods. -->
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
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  year: new Date().getFullYear(),
  start_date: `${new Date().getFullYear()}-01-01`,
})

const companyOptions = props.companies.map((c) => ({ value: c.id, label: c.legal_name }))

const submit = () => form.transform((data) => ({ ...data, year: Number(data.year) })).post(route('accounting.fiscal-years.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Fiscal Year" description="Creates the annual fiscal calendar and auto-generates 12 monthly periods." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companyOptions" :error="form.errors.company_id" required />
        <FormInput v-model="form.year" name="year" type="number" label="Fiscal Year" :error="form.errors.year" required />
        <FormInput v-model="form.start_date" name="start_date" type="date" label="Start Date" :error="form.errors.start_date" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.fiscal-years.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Fiscal Year</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
