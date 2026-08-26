<!-- ponytail: Accounting §3P recurring AR invoice template — mirrors ArInvoices/Create.vue's
     line table, with recurrence_rule/anchor_date/payment_terms_days replacing the one-off
     issue_date/due_date pair (due_date is computed at generation time from issue_date + terms). -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'
import { Plus, Trash2 } from 'lucide-vue-next'

type Option = { value: number; label: string }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  revenueAccounts: Option[]
  taxCodes: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
  invoiceTypes: string[]
}>()

const blankLine = () => ({
  description: '',
  qty: 1 as number | null,
  unit_price: 0 as number | null,
  discount_amount: 0 as number | null,
  tax_code_id: null as number | null,
  revenue_account_id: props.revenueAccounts[0]?.value ?? null,
})

const form = useForm({
  company_id: props.selectedCompanyId,
  partner_id: null as number | null,
  name: '',
  currency_code: props.currencies[0]?.code ?? 'IDR',
  invoice_type: 'standard',
  payment_terms_days: 30,
  recurrence_rule: '',
  anchor_date: new Date().toISOString().slice(0, 10),
  lines: [blankLine()],
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))
const invoiceTypeOptions = props.invoiceTypes.map((t) => ({ value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }))

const addLine = () => form.lines.push(blankLine())
const removeLine = (i: number) => form.lines.splice(i, 1)

const taxRate = (taxCodeId: number | null) => {
  const match = props.taxCodes.find((t) => t.value === taxCodeId)
  const m = match?.label.match(/\(([\d.]+)%\)/)
  return m ? Number(m[1]) : 0
}

const lineAmount = (l: ReturnType<typeof blankLine>) => (Number(l.qty) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount_amount) || 0)
const lineTax = (l: ReturnType<typeof blankLine>) => (lineAmount(l) * taxRate(l.tax_code_id)) / 100

const subtotal = computed(() => form.lines.reduce((sum, l) => sum + lineAmount(l), 0))
const taxTotal = computed(() => form.lines.reduce((sum, l) => sum + lineTax(l), 0))
const grandTotal = computed(() => subtotal.value + taxTotal.value)

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, qty: Number(l.qty) || 0, unit_price: Number(l.unit_price) || 0, discount_amount: Number(l.discount_amount) || 0 })),
})).post(route('accounting.recurring-ar-templates.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="New Recurring Invoice Template" description="Drafted automatically each time the rule comes due — reviewed and posted manually." />

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormSearchableSelect
            v-model="form.company_id"
            name="company_id"
            label="Company"
            :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
            :error="form.errors.company_id"
            required
          />
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Customer" api-entity="crm_partner" placeholder="Search customer..." :error="form.errors.partner_id" required />
          <FormInput v-model="form.name" name="name" label="Template Name" placeholder="e.g. Monthly Retainer" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
          <FormSelect v-model="form.invoice_type" name="invoice_type" label="Invoice Type" :options="invoiceTypeOptions" :error="form.errors.invoice_type" required />
          <FormInput v-model="form.payment_terms_days" name="payment_terms_days" type="number" min="0" label="Payment Terms (days)" :error="form.errors.payment_terms_days" required />
          <FormInput v-model="form.anchor_date" name="anchor_date" type="date" label="First Occurrence (Issue Date)" :error="form.errors.anchor_date" required />
          <FormInput
            v-model="form.recurrence_rule"
            name="recurrence_rule"
            label="Recurrence Rule (iCal RRule)"
            placeholder="e.g. FREQ=MONTHLY;BYMONTHDAY=1"
            :error="form.errors.recurrence_rule"
            required
          />
        </div>

        <div>
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Template Lines</h3>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-accent hover:underline" @click="addLine">
              <Plus class="h-4 w-4" /> Add Line
            </button>
          </div>

          <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                  <th class="w-1/4 px-3 py-2.5">Description</th>
                  <th class="px-3 py-2.5">Revenue Account</th>
                  <th class="px-3 py-2.5 text-right">Qty</th>
                  <th class="px-3 py-2.5 text-right">Unit Price</th>
                  <th class="px-3 py-2.5 text-right">Discount</th>
                  <th class="px-3 py-2.5">Tax Code</th>
                  <th class="px-3 py-2.5 text-right">Amount</th>
                  <th class="px-3 py-2.5"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="(line, i) in form.lines" :key="i" class="align-top hover:bg-surface-50/50 transition-colors">
                  <td class="px-3 py-2">
                    <input v-model="line.description" type="text" placeholder="Item description" class="w-full rounded-md border border-border bg-surface-0 px-2.5 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.revenue_account_id" :name="`lines.${i}.revenue_account_id`" :options="revenueAccounts" />
                  </td>
                  <td class="px-3 py-2">
                    <FormNumberInput v-model="line.qty" :name="`lines.${i}.qty`" :decimals="2" class="w-24" />
                  </td>
                  <td class="px-3 py-2">
                    <FormCurrencyInput v-model="line.unit_price" :name="`lines.${i}.unit_price`" prefix="" :decimals="2" class="w-32" />
                  </td>
                  <td class="px-3 py-2">
                    <FormCurrencyInput v-model="line.discount_amount" :name="`lines.${i}.discount_amount`" prefix="" :decimals="2" class="w-28" />
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.tax_code_id" :name="`lines.${i}.tax_code_id`" placeholder="None" :options="taxCodes" />
                  </td>
                  <td class="px-3 py-2 text-right font-mono text-xs font-semibold text-ink-900 pt-3.5">{{ formatCurrency(lineAmount(line) + lineTax(line), form.currency_code) }}</td>
                  <td class="px-3 py-2 text-right pt-3">
                    <button type="button" class="text-ink-400 hover:text-signal-danger transition-colors" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t-2 border-border bg-surface-100/75 font-semibold text-xs">
                  <td class="px-4 py-3 text-ink-900" colspan="5">Subtotal / Tax / Total</td>
                  <td class="px-4 py-3 text-right font-mono text-accent font-bold" colspan="3">
                    {{ formatCurrency(subtotal, form.currency_code) }} + {{ formatCurrency(taxTotal, form.currency_code) }} = {{ formatCurrency(grandTotal, form.currency_code) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="(form.errors as any).lines" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.recurring-ar-templates.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Save Template</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
