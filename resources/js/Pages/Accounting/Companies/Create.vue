<!-- ponytail: Accounting §3K minimal Companies master — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const form = useForm({
  legal_name: '',
  npwp: '',
  address: '',
  base_currency: 'IDR',
  fiscal_year_start_month: 1,
})

const submit = () => form.transform((data) => ({
  ...data,
  fiscal_year_start_month: Number(data.fiscal_year_start_month),
})).post(route('accounting.companies.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New company" description="A legal entity inside this tenant (e.g. the operating company or a client-trust entity)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormInput v-model="form.legal_name" name="legal_name" label="Legal name" :error="form.errors.legal_name" required />
        <FormInput v-model="form.npwp" name="npwp" label="NPWP" :error="form.errors.npwp" />
        <FormInput v-model="form.address" name="address" label="Address" :error="form.errors.address" />
        <FormInput v-model="form.base_currency" name="base_currency" label="Base currency" :error="form.errors.base_currency" required />
        <FormInput v-model="form.fiscal_year_start_month" name="fiscal_year_start_month" type="number" label="Fiscal year start month (1-12)" :error="form.errors.fiscal_year_start_month" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.companies.index')"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create company</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
