<!-- ponytail: Accounting §3M PPh withholding types — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormSwitch from '@/Components/forms/FormSwitch.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: Array<{ value: number; label: string }>
  bpTypes: string[]
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  bp_type: null as string | null,
  name: '',
  rate: 2 as number | null,
  is_final: false,
  gl_payable_account_id: null as number | null,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).post(route('accounting.withholding-types.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Withholding Tax Type" description="PPh 23, 4(2) final, 21 non-employee, and other withholding configurations." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Withholding Code" placeholder="e.g. PPH23_SERVICES, PPH4_RENT" :error="form.errors.code" required />
        <FormSelect
          v-model="form.bp_type"
          name="bp_type"
          label="Bukti Potong Type"
          placeholder="Select Bukti Potong type..."
          :options="bpTypes.map((t) => ({ label: t, value: t }))"
          :error="form.errors.bp_type"
        />
        <FormInput v-model="form.name" name="name" label="Withholding Type Name" placeholder="e.g. PPh Pasal 23 Jasa" :error="form.errors.name" required />
        <FormNumberInput v-model="form.rate" name="rate" label="Withholding Rate (%)" suffix="%" :error="form.errors.rate" required />
        <FormSearchableSelect v-model="form.gl_payable_account_id" name="gl_payable_account_id" label="Withholding Tax Payable GL Account" :options="accounts" :error="form.errors.gl_payable_account_id" required />

        <FormSwitch
          v-model="form.is_final"
          name="is_final"
          label="Final Tax (e.g. PPh 4(2) on rental / construction)"
        />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.withholding-types.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Withholding Type</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
