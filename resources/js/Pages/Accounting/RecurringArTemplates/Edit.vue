<!-- ponytail: Accounting §3P recurring AR invoice template edit — same shape as Create.vue plus
     status/upcoming-occurrences panels and pause/resume/delete, mirrors
     RecurringJournalTemplates/Edit.vue's structure. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useForm, router, Link } from '@inertiajs/vue3'
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
import DangerButton from '@/Components/DangerButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

type Option = { value: number; label: string }
type TemplateLine = { description: string; qty: number; unit_price: number; discount_amount: number; tax_code_id: number | null; revenue_account_id: number | null }

const props = defineProps<{
  template: {
    id: number; company_id: number; partner_id: number; partner_name: string | null; name: string
    currency_code: string; invoice_type: string; payment_terms_days: number
    recurrence_rule: string; anchor_date: string; next_run_date: string | null; last_run_date: string | null
    is_active: boolean; lines: TemplateLine[]
  }
  upcomingRunDates: string[]
  revenueAccounts: Option[]
  taxCodes: Array<{ value: number; label: string }>
  currencies: Array<{ code: string; name: string }>
  invoiceTypes: string[]
}>()

const form = useForm({
  partner_id: props.template.partner_id as number | null,
  name: props.template.name,
  currency_code: props.template.currency_code,
  invoice_type: props.template.invoice_type,
  payment_terms_days: props.template.payment_terms_days,
  recurrence_rule: props.template.recurrence_rule,
  anchor_date: props.template.anchor_date,
  lines: props.template.lines.map((l) => ({ ...l, qty: Number(l.qty) as number | null, unit_price: Number(l.unit_price) as number | null, discount_amount: Number(l.discount_amount) as number | null })),
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))
const invoiceTypeOptions = props.invoiceTypes.map((t) => ({ value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }))

const addLine = () => form.lines.push({ description: '', qty: 1, unit_price: 0, discount_amount: 0, tax_code_id: null, revenue_account_id: props.revenueAccounts[0]?.value ?? null })
const removeLine = (i: number) => form.lines.splice(i, 1)

const taxRate = (taxCodeId: number | null) => {
  const match = props.taxCodes.find((t) => t.value === taxCodeId)
  const m = match?.label.match(/\(([\d.]+)%\)/)
  return m ? Number(m[1]) : 0
}

const lineAmount = (l: { qty: number | null; unit_price: number | null; discount_amount: number | null }) => (Number(l.qty) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount_amount) || 0)
const lineTax = (l: { qty: number | null; unit_price: number | null; discount_amount: number | null; tax_code_id: number | null }) => (lineAmount(l) * taxRate(l.tax_code_id)) / 100

const subtotal = computed(() => form.lines.reduce((sum, l) => sum + lineAmount(l), 0))
const taxTotal = computed(() => form.lines.reduce((sum, l) => sum + lineTax(l), 0))
const grandTotal = computed(() => subtotal.value + taxTotal.value)

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, qty: Number(l.qty) || 0, unit_price: Number(l.unit_price) || 0, discount_amount: Number(l.discount_amount) || 0 })),
})).put(route('accounting.recurring-ar-templates.update', props.template.id))

const toggleActive = () => router.post(route('accounting.recurring-ar-templates.set-active', props.template.id), { is_active: !props.template.is_active }, { preserveScroll: true })

const { confirm } = useConfirm()

const destroy = () => {
  confirm({
    title: 'Delete Recurring Template?',
    description: `Delete recurring template "${props.template.name}"? This does not affect invoices already generated.`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.recurring-ar-templates.destroy', props.template.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="template.name" description="Editing the rule or anchor date recomputes the next occurrence from scratch.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton type="button" @click="toggleActive">
            {{ template.is_active ? 'Pause Template' : 'Resume Template' }}
          </SecondaryButton>
          <DangerButton type="button" @click="destroy">
            Delete
          </DangerButton>
          <SecondaryButton :href="route('accounting.recurring-ar-templates.index', { company_id: template.company_id })">
            &larr; Templates
          </SecondaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <div class="mt-2"><StatusBadge :status="template.is_active ? 'active' : 'paused'" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Last Run</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ template.last_run_date ? formatDate(template.last_run_date) : 'Never' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Next Run</div>
        <div class="mt-2 text-sm font-medium text-ink-900">{{ template.next_run_date ? formatDate(template.next_run_date) : 'Exhausted' }}</div>
      </Panel>
    </div>

    <Panel v-if="upcomingRunDates.length" class="mt-4 p-4">
      <div class="text-xs font-semibold uppercase text-ink-600">Upcoming Occurrences (Preview)</div>
      <div class="mt-2 flex flex-wrap gap-2">
        <span v-for="d in upcomingRunDates" :key="d" class="rounded-md border border-border bg-surface-50 px-2.5 py-1 text-xs font-mono text-ink-700">
          {{ formatDate(d) }}
        </span>
      </div>
    </Panel>

    <Panel class="mt-6">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Customer" api-entity="crm_partner" :error="form.errors.partner_id" required />
          <FormInput v-model="form.name" name="name" label="Template Name" :error="form.errors.name" required />
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
          <PrimaryButton type="submit" :disabled="form.processing">Save Changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
