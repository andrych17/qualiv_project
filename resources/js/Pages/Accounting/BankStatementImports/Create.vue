<!-- ponytail: Accounting §3F bank statement import — CSV only, explicit column mapping (see BankStatementImportService docblock for why nothing is guessed from headers). -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  bankAccounts: Array<{ value: number; label: string }>
}>()

const form = useForm({
  company_id: props.selectedCompanyId,
  bank_account_id: null as number | null,
  file: null as File | null,
  date_column: '0',
  description_column: '1',
  amount_column: '2',
  reference_column: '',
})

const dragOver = ref(false)
const onDropFile = (event: DragEvent) => {
  dragOver.value = false
  form.file = event.dataTransfer?.files?.[0] ?? null
}

const submit = () => form.transform((data) => ({
  ...data,
  date_column: Number(data.date_column),
  description_column: Number(data.description_column),
  amount_column: Number(data.amount_column),
  reference_column: data.reference_column === '' ? null : Number(data.reference_column),
})).post(route('accounting.bank-statement-imports.store'), { forceFormData: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Import bank statement" description="CSV only — the first row is always treated as a header and skipped. Column numbers are 0-based (the first column is 0)." />

    <Panel class="mt-6 max-w-xl">
      <form class="space-y-4" @submit.prevent="submit">
        <FormSearchableSelect v-model="form.company_id" name="company_id" label="Company" :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))" :error="form.errors.company_id" required />
        <FormSearchableSelect v-model="form.bank_account_id" name="bank_account_id" label="Bank account" :options="bankAccounts" :error="form.errors.bank_account_id" required />

        <div
          class="rounded-md border-2 border-dashed border-border bg-surface-50 p-4 text-center transition"
          :class="dragOver ? 'border-accent bg-accent/5' : ''"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop.prevent="onDropFile"
        >
          <input id="statement-file" type="file" accept=".csv,.txt" class="sr-only" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
          <label for="statement-file" class="inline-block cursor-pointer rounded-sm p-2 text-sm text-ink-600 transition hover:text-accent">
            {{ form.file ? form.file.name : 'Drop a CSV file here, or click to choose' }}
          </label>
          <p v-if="form.errors.file" class="mt-1 text-sm text-signal-danger">{{ form.errors.file }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <FormInput v-model="form.date_column" name="date_column" type="number" min="0" label="Date column #" :error="form.errors.date_column" required />
          <FormInput v-model="form.description_column" name="description_column" type="number" min="0" label="Description column #" :error="form.errors.description_column" required />
          <FormInput v-model="form.amount_column" name="amount_column" type="number" min="0" label="Amount column #" :error="form.errors.amount_column" required />
          <FormInput v-model="form.reference_column" name="reference_column" type="number" min="0" label="Reference column # (optional)" :error="form.errors.reference_column" />
        </div>
        <p class="text-xs text-ink-600">Amount is signed — positive for money in, negative for money out, matching how most bank exports already format it.</p>
        <p v-if="(form.errors as any).file" class="text-sm text-signal-danger">{{ (form.errors as any).file }}</p>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.bank-statement-imports.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Import</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
