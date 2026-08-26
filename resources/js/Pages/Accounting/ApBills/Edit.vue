<!-- ponytail: Accounting §3E vendor bill — edit (draft only). -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { formatCurrency } from '@/Utils/formatters'
import { Plus, Trash2 } from 'lucide-vue-next'

type Option = { value: number; label: string }
type LineRow = { description: string; qty: number; unit_price: number; discount_amount: number; tax_code_id: number | null; expense_account_id: number | null }

const props = defineProps<{
  bill: {
    id: number
    company_id: number
    partner_id: number
    bill_no: string
    currency_code: string
    issue_date: string
    due_date: string
    vendor_faktur_no: string | null
    withholding_type_id: number | null
    lines: LineRow[]
  }
  expenseAccounts: Option[]
  taxCodes: Array<{ value: number; label: string }>
  withholdingTypes: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
}>()

const blankLine = () => ({ description: '', qty: 1 as number | null, unit_price: 0 as number | null, discount_amount: 0 as number | null, tax_code_id: null as number | null, expense_account_id: (props.expenseAccounts[0]?.value ?? null) as number | null })
const toFormLine = (l: LineRow) => ({ description: l.description, qty: Number(l.qty) as number | null, unit_price: Number(l.unit_price) as number | null, discount_amount: Number(l.discount_amount) as number | null, tax_code_id: l.tax_code_id, expense_account_id: l.expense_account_id as number | null })

const form = useForm({
  partner_id: props.bill.partner_id as number | null,
  bill_no: props.bill.bill_no,
  currency_code: props.bill.currency_code,
  issue_date: props.bill.issue_date,
  due_date: props.bill.due_date,
  vendor_faktur_no: props.bill.vendor_faktur_no ?? '',
  withholding_type_id: props.bill.withholding_type_id,
  lines: props.bill.lines.length ? props.bill.lines.map(toFormLine) : [blankLine()],
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))

const addLine = () => form.lines.push(blankLine())
const removeLine = (i: number) => form.lines.splice(i, 1)

const taxRate = (taxCodeId: number | null) => {
  const match = props.taxCodes.find((t) => t.value === taxCodeId)
  const m = match?.label.match(/\(([\d.]+)%\)/)
  return m ? Number(m[1]) : 0
}

const lineAmount = (l: ReturnType<typeof blankLine>) => (Number(l.qty) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount_amount) || 0)
const lineTax = (l: ReturnType<typeof blankLine>) => (lineAmount(l) * taxRate(l.tax_code_id)) / 100
const grandTotal = computed(() => form.lines.reduce((sum, l) => sum + lineAmount(l) + lineTax(l), 0))

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, qty: Number(l.qty) || 0, unit_price: Number(l.unit_price) || 0, discount_amount: Number(l.discount_amount) || 0 })),
})).put(route('accounting.ap-bills.update', props.bill.id))
</script>

<template>
  <AppLayout>
    <PageHeader title="Edit bill" description="Draft only — withholding and tax rules are enforced when you post it." />

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Vendor" api-entity="crm_partner" :error="form.errors.partner_id" required />
          <FormInput v-model="form.bill_no" name="bill_no" label="Vendor's bill/invoice no." :error="form.errors.bill_no" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
          <FormInput v-model="form.issue_date" name="issue_date" type="date" label="Issue date" :error="form.errors.issue_date" required />
          <FormInput v-model="form.due_date" name="due_date" type="date" label="Due date" :error="form.errors.due_date" required />
          <FormInput v-model="form.vendor_faktur_no" name="vendor_faktur_no" label="Vendor's Faktur Pajak no." :error="form.errors.vendor_faktur_no" />
          <FormSearchableSelect v-model="form.withholding_type_id" name="withholding_type_id" label="Withholding (PPh)" placeholder="None" :options="withholdingTypes" :error="form.errors.withholding_type_id" />
        </div>

        <div>
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-ink-900">Lines</h3>
            <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline" @click="addLine">
              <Plus class="h-4 w-4" /> Add line
            </button>
          </div>

          <div class="overflow-x-auto rounded-sm border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase text-ink-600">
                  <th class="w-1/4 px-3 py-2">Description</th>
                  <th class="px-3 py-2">Expense/asset account</th>
                  <th class="px-3 py-2 text-right">Qty</th>
                  <th class="px-3 py-2 text-right">Unit price</th>
                  <th class="px-3 py-2 text-right">Discount</th>
                  <th class="px-3 py-2">Tax code</th>
                  <th class="px-3 py-2 text-right">Amount</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(line, i) in form.lines" :key="i" class="border-b border-border last:border-b-0 align-top">
                  <td class="px-3 py-2">
                    <input v-model="line.description" type="text" class="w-full rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.expense_account_id" :name="`lines.${i}.expense_account_id`" :options="expenseAccounts" />
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
                  <td class="px-3 py-2 text-right font-medium text-ink-900">{{ formatCurrency(lineAmount(line) + lineTax(line), form.currency_code) }}</td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="text-ink-600 hover:text-signal-danger pt-2" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t border-border bg-surface-50 font-semibold">
                  <td class="px-3 py-2" colspan="6">Total</td>
                  <td class="px-3 py-2 text-right" colspan="2">{{ formatCurrency(grandTotal, form.currency_code) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="(form.errors as any).lines" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.ap-bills.show', bill.id)"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
