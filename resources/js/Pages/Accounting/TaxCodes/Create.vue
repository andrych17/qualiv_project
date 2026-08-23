<!-- ponytail: Accounting §3M PPN tax codes — create form. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  accounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  rate: 11,
  tax_type: 'output',
  gl_account_id: null as number | null,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).post(route('accounting.tax-codes.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New tax code" />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Code" :error="form.errors.code" required />
        <FormInput v-model="form.rate" name="rate" type="number" label="Rate (%)" :error="form.errors.rate" required />
        <FormSelect
          v-model="form.tax_type"
          name="tax_type"
          label="Type"
          :options="[{ label: 'Output (Keluaran)', value: 'output' }, { label: 'Input (Masukan)', value: 'input' }]"
          :error="form.errors.tax_type"
          required
        />
        <FormSearchableSelect v-model="form.gl_account_id" name="gl_account_id" label="GL account" :options="accounts" :error="form.errors.gl_account_id" required />

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.tax-codes.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create tax code</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
