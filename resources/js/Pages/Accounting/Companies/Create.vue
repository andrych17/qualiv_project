<!-- ponytail: Accounting §3K minimal Companies master — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const form = useForm({
  legal_name: '',
  npwp: '',
  address: '',
  base_currency: 'IDR',
  fiscal_year_start_month: 1 as number | null,
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_year_start_month: Number(data.fiscal_year_start_month),
})).post(route('accounting.companies.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Company" description="A legal entity inside this tenant (e.g. operating legal company or client-trust entity)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.legal_name" name="legal_name" label="Legal Name" placeholder="e.g. Qualiv AI" :error="form.errors.legal_name" required />
        <FormInput v-model="form.npwp" name="npwp" label="NPWP (Tax ID)" placeholder="e.g. 01.234.567.8-012.000" :error="form.errors.npwp" />
        <FormInput v-model="form.address" name="address" label="Registered Address" :error="form.errors.address" />
        <FormInput v-model="form.base_currency" name="base_currency" label="Base Functional Currency" placeholder="IDR" :error="form.errors.base_currency" required />
        <FormNumberInput v-model="form.fiscal_year_start_month" name="fiscal_year_start_month" label="Fiscal Year Start Month (1-12)" :min="1" :max="12" :error="form.errors.fiscal_year_start_month" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.companies.index')">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Create Company</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
