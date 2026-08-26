<!-- ponytail: Accounting §3M — register a tax period (masa pajak); due_date is computed server-side. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
}>()

const now = new Date()
const defaultMasaPajak = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`

const form = useForm({
  company_id: props.selectedCompanyId,
  obligation_type: 'ppn',
  masa_pajak: defaultMasaPajak,
})

const submit = () => form.post(route('accounting.tax-periods.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Register Tax Period (Masa Pajak)" description="Due dates are calculated automatically based on Indonesian tax statutory filing deadlines." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSelect
          v-model="form.obligation_type"
          name="obligation_type"
          label="Tax Obligation"
          :options="[{ label: 'PPN (VAT)', value: 'ppn' }, { label: 'PPh (Income Tax)', value: 'pph' }]"
          :error="form.errors.obligation_type"
          required
        />
        <FormInput v-model="form.masa_pajak" name="masa_pajak" type="month" label="Masa Pajak (YYYY-MM)" :error="form.errors.masa_pajak" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.tax-periods.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Register Period</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
