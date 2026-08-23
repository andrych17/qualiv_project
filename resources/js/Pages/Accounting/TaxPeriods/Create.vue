<!-- ponytail: Accounting §3M — register a tax period (masa pajak); due_date is computed server-side. -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
    <PageHeader title="Register tax period" description="Due date is computed from the configured due-day rule (SYSCONFIG constants ACCOUNTING_TAX.PPN_DUE_DAY_OF_MONTH / PPH_DUE_DAY_OF_MONTH)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSelect
          v-model="form.obligation_type"
          name="obligation_type"
          label="Obligation"
          :options="[{ label: 'PPN', value: 'ppn' }, { label: 'PPh', value: 'pph' }]"
          :error="form.errors.obligation_type"
          required
        />
        <div class="space-y-1.5">
          <label class="text-sm font-medium text-ink-900">Masa pajak (YYYY-MM)<span class="text-signal-danger">*</span></label>
          <input
            v-model="form.masa_pajak"
            type="month"
            class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm shadow-sm outline-none focus:border-accent focus:ring-2 focus:ring-accent/10"
          />
          <p v-if="form.errors.masa_pajak" class="text-sm text-signal-danger">{{ form.errors.masa_pajak }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.tax-periods.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Register period</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
