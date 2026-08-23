<!-- ponytail: Accounting §3M PPh withholding types — create form. -->
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
  bpTypes: string[]
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  code: '',
  bp_type: null as string | null,
  name: '',
  rate: 2,
  is_final: false,
  gl_payable_account_id: null as number | null,
})

const submit = () => form.transform((data) => ({ ...data, rate: Number(data.rate) })).post(route('accounting.withholding-types.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New withholding type" description="MVP covers PPh 23 (services), 4(2) (final, e.g. rent), 21 (non-payroll professional/director fees) — 22/15 are config, not code." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormInput v-model="form.code" name="code" label="Code" placeholder="e.g. PPh23, PPh4A2, PPh21" :error="form.errors.code" required />
        <FormSelect
          v-model="form.bp_type"
          name="bp_type"
          label="Bukti Potong type"
          placeholder="None — bills using this type can't post until set"
          :options="bpTypes.map((t) => ({ label: t, value: t }))"
          :error="form.errors.bp_type"
        />
        <FormInput v-model="form.name" name="name" label="Name" :error="form.errors.name" required />
        <FormInput v-model="form.rate" name="rate" type="number" label="Rate (%)" :error="form.errors.rate" required />
        <FormSearchableSelect v-model="form.gl_payable_account_id" name="gl_payable_account_id" label="Payable GL account" :options="accounts" :error="form.errors.gl_payable_account_id" required />

        <label class="flex items-center gap-2 text-sm text-ink-900">
          <input v-model="form.is_final" type="checkbox" class="rounded border-border" />
          Final (e.g. PPh 4(2) on rent)
        </label>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.withholding-types.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Create withholding type</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
