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
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { Plus, Trash2 } from 'lucide-vue-next'

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
  lines: props.template.lines.map((l) => ({ ...l, qty: String(l.qty), unit_price: String(l.unit_price), discount_amount: String(l.discount_amount) })),
})

const currencyOptions = props.currencies.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }))
const invoiceTypeOptions = props.invoiceTypes.map((t) => ({ value: t, label: t.charAt(0).toUpperCase() + t.slice(1) }))

const addLine = () => form.lines.push({ description: '', qty: '1', unit_price: '', discount_amount: '0', tax_code_id: null, revenue_account_id: props.revenueAccounts[0]?.value ?? null })
const removeLine = (i: number) => form.lines.splice(i, 1)

const taxRate = (taxCodeId: number | null) => {
  const match = props.taxCodes.find((t) => t.value === taxCodeId)
  const m = match?.label.match(/\(([\d.]+)%\)/)
  return m ? Number(m[1]) : 0
}

const lineAmount = (l: { qty: string; unit_price: string; discount_amount: string }) => (Number(l.qty) || 0) * (Number(l.unit_price) || 0) - (Number(l.discount_amount) || 0)
const lineTax = (l: { qty: string; unit_price: string; discount_amount: string; tax_code_id: number | null }) => (lineAmount(l) * taxRate(l.tax_code_id)) / 100

const subtotal = computed(() => form.lines.reduce((sum, l) => sum + lineAmount(l), 0))
const taxTotal = computed(() => form.lines.reduce((sum, l) => sum + lineTax(l), 0))
const grandTotal = computed(() => subtotal.value + taxTotal.value)

const submit = () => form.transform((data) => ({
  ...data,
  lines: data.lines.map((l) => ({ ...l, qty: Number(l.qty) || 0, unit_price: Number(l.unit_price) || 0, discount_amount: Number(l.discount_amount) || 0 })),
})).put(route('accounting.recurring-ar-templates.update', props.template.id))

const toggleActive = () => router.post(route('accounting.recurring-ar-templates.set-active', props.template.id), { is_active: !props.template.is_active }, { preserveScroll: true })

const destroy = () => {
  if (confirm(`Delete recurring template "${props.template.name}"? This does not affect invoices already generated.`)) {
    router.delete(route('accounting.recurring-ar-templates.destroy', props.template.id))
  }
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="template.name" description="Editing the rule or anchor date recomputes the next occurrence from scratch.">
      <template #actions>
        <button type="button" class="mr-4 text-sm font-medium text-accent hover:underline" @click="toggleActive">{{ template.is_active ? 'Pause' : 'Resume' }}</button>
        <button type="button" class="mr-4 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <Link :href="route('accounting.recurring-ar-templates.index', { company_id: template.company_id })" class="text-sm font-medium text-accent hover:underline">← Templates</Link>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <div class="mt-1"><StatusBadge :status="template.is_active ? 'active' : 'paused'" /></div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Last run</div>
        <div class="mt-1 text-sm text-ink-900">{{ template.last_run_date ?? 'Never' }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Next run</div>
        <div class="mt-1 text-sm text-ink-900">{{ template.next_run_date ?? 'Exhausted — rule has no further occurrences' }}</div>
      </Panel>
    </div>

    <Panel v-if="upcomingRunDates.length" class="mt-4 p-4">
      <div class="text-xs uppercase text-ink-600">Upcoming occurrences (preview)</div>
      <div class="mt-2 flex flex-wrap gap-2">
        <span v-for="d in upcomingRunDates" :key="d" class="rounded-full bg-surface-50 px-2.5 py-0.5 text-xs text-ink-700">{{ d }}</span>
      </div>
    </Panel>

    <Panel class="mt-4">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Customer" api-entity="crm_partner" :error="form.errors.partner_id" required />
          <FormInput v-model="form.name" name="name" label="Template name" :error="form.errors.name" required />
          <FormSearchableSelect v-model="form.currency_code" name="currency_code" label="Currency" :options="currencyOptions" :error="form.errors.currency_code" required />
          <FormSelect v-model="form.invoice_type" name="invoice_type" label="Invoice type" :options="invoiceTypeOptions" :error="form.errors.invoice_type" required />
          <FormInput v-model="form.payment_terms_days" name="payment_terms_days" type="number" min="0" label="Payment terms (days)" :error="form.errors.payment_terms_days" required />
          <FormInput v-model="form.anchor_date" name="anchor_date" type="date" label="First occurrence (issue date)" :error="form.errors.anchor_date" required />
          <FormInput
            v-model="form.recurrence_rule"
            name="recurrence_rule"
            label="Recurrence rule"
            placeholder="e.g. FREQ=MONTHLY;BYMONTHDAY=1"
            :error="form.errors.recurrence_rule"
            required
          />
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
                  <th class="px-3 py-2">Revenue account</th>
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
                    <FormSearchableSelect v-model="line.revenue_account_id" :name="`lines.${i}.revenue_account_id`" :options="revenueAccounts" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.qty" type="number" step="0.0001" min="0" class="w-20 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.unit_price" type="number" step="0.01" min="0" class="w-28 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.discount_amount" type="number" step="0.01" min="0" class="w-24 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                  <td class="px-3 py-2">
                    <FormSearchableSelect v-model="line.tax_code_id" :name="`lines.${i}.tax_code_id`" placeholder="None" :options="taxCodes" />
                  </td>
                  <td class="px-3 py-2 text-right text-ink-900">{{ (lineAmount(line) + lineTax(line)).toFixed(2) }}</td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="text-ink-600 hover:text-signal-danger" :disabled="form.lines.length <= 1" @click="removeLine(i)">
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t border-border bg-surface-50 font-semibold">
                  <td class="px-3 py-2" colspan="6">Subtotal / Tax / Total</td>
                  <td class="px-3 py-2 text-right" colspan="2">{{ subtotal.toFixed(2) }} / {{ taxTotal.toFixed(2) }} / {{ grandTotal.toFixed(2) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="(form.errors as any).lines" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).lines }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <PrimaryButton type="submit" :disabled="form.processing">Save changes</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
