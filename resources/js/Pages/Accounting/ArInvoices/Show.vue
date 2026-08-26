<!-- ponytail: Accounting §3D invoice detail — draft is editable/postable/deletable; posted+
     is where payments/credit notes/Faktur Pajak live. Credit-note issuance is inline here
     (v1 scope, no separate credit-note screens — see ArCreditNoteController docblock). -->
<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface LineRow {
  description: string
  qty: number
  unit_price: number
  discount_amount: number
  tax_code: string | null
  revenue_account: string
  line_amount: number
  tax_amount: number
}

interface CreditNoteRow {
  id: number
  credit_note_no: string
  credit_date: string
  amount: number
  status: string
}

const props = defineProps<{
  invoice: {
    id: number
    company_id: number
    invoice_no: string
    invoice_type: string
    partner_id: number
    partner_name: string | null
    currency_code: string
    issue_date: string
    due_date: string
    status: string
    subtotal: number
    tax_amount: number
    total_amount: number
    paid_amount: number
    credited_amount: number
    open_balance: number
    journal_id: number | null
    faktur_pajak: { nomor_seri_faktur: string; status: string } | null
    lines: LineRow[]
    credit_notes: CreditNoteRow[]
  }
  revenueAccounts: Array<{ value: number; label: string }>
}>()

const { confirm } = useConfirm()

const post = () => {
  confirm({
    title: 'Post this invoice?',
    description: 'Creates the AR journal and, if any line is taxable, issues a Faktur Pajak. Corrections after this point require a credit note, never an edit.',
    confirmText: 'Post',
    onConfirm: () => router.post(route('accounting.ar-invoices.post', props.invoice.id)),
  })
}

const destroy = () => {
  confirm({
    title: 'Delete this draft invoice?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.ar-invoices.destroy', props.invoice.id)),
  })
}

const showCreditForm = ref(false)
const creditForm = useForm({
  ar_invoice_id: props.invoice.id,
  credit_date: new Date().toISOString().slice(0, 10),
  amount: null as number | null,
  reason: '',
  revenue_account_id: props.revenueAccounts[0]?.value ?? null,
})

const submitCreditNote = () => creditForm.transform((data) => ({ ...data, amount: Number(data.amount) || 0 })).post(route('accounting.ar-credit-notes.store'), {
  preserveScroll: true,
  onSuccess: () => { showCreditForm.value = false },
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="invoice.invoice_no" :description="`${invoice.partner_name ?? '—'} — ${formatDate(invoice.issue_date)} — Due ${formatDate(invoice.due_date)}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.ar-invoices.index', { company_id: invoice.company_id })">
            &larr; Back to Invoices
          </SecondaryButton>
          <SecondaryButton v-if="invoice.status === 'draft'" :href="route('accounting.ar-invoices.edit', invoice.id)">
            Edit
          </SecondaryButton>
          <DangerButton v-if="invoice.status === 'draft'" type="button" @click="destroy">
            Delete
          </DangerButton>
          <SecondaryButton v-if="invoice.open_balance > 0.005" :href="route('accounting.ar-payments.create', { company_id: invoice.company_id, partner_id: invoice.partner_id })">
            Record Payment
          </SecondaryButton>
          <PrimaryButton v-if="invoice.status === 'draft'" type="button" @click="post">
            Post Invoice
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-2" :status="invoice.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Gross Total</div>
        <div class="mt-2 font-mono text-sm font-bold text-ink-900">{{ formatCurrency(invoice.total_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Paid</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ formatCurrency(invoice.paid_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Credited</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ formatCurrency(invoice.credited_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Open Balance</div>
        <div class="mt-2 font-mono text-sm font-bold" :class="invoice.open_balance > 0.005 ? 'text-amber-700' : 'text-emerald-700'">
          {{ formatCurrency(invoice.open_balance) }}
        </div>
      </Panel>
    </div>

    <Panel v-if="invoice.faktur_pajak" class="mt-4 p-4 text-sm">
      <span class="text-ink-600 font-medium">Nomor Seri Faktur Pajak:</span>
      <span class="ml-2 font-mono font-medium text-ink-900">{{ invoice.faktur_pajak.nomor_seri_faktur }}</span>
      <StatusBadge class="ml-2" :status="invoice.faktur_pajak.status" />
    </Panel>

    <Panel class="mt-6">
      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Description</th>
              <th class="py-3 px-4">Account</th>
              <th class="py-3 px-4 text-right">Qty</th>
              <th class="py-3 px-4 text-right">Unit Price</th>
              <th class="py-3 px-4">Tax</th>
              <th class="py-3 px-4 text-right">Line Total</th>
              <th class="py-3 px-4 text-right">Tax Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="(l, i) in invoice.lines" :key="i" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-medium text-ink-900">{{ l.description }}</td>
              <td class="py-3 px-4 text-xs font-mono text-ink-700">{{ l.revenue_account }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-700">{{ l.qty }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-700">{{ formatCurrency(l.unit_price) }}</td>
              <td class="py-3 px-4 text-xs font-medium text-ink-700">{{ l.tax_code ?? '—' }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(l.line_amount) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-ink-700">{{ formatCurrency(l.tax_amount) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-border bg-surface-100/75 font-semibold">
              <td class="py-3 px-4 text-ink-900" colspan="5">Subtotal / Tax / Gross</td>
              <td class="py-3 px-4 text-right font-mono text-xs text-accent font-bold" colspan="2">
                {{ formatCurrency(invoice.subtotal) }} + {{ formatCurrency(invoice.tax_amount) }} = {{ formatCurrency(invoice.total_amount) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>

    <Panel v-if="invoice.status !== 'draft'" class="mt-6">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-ink-900">Credit Notes</h3>
        <button v-if="invoice.open_balance > 0.005" type="button" class="text-xs font-semibold text-accent hover:underline" @click="showCreditForm = !showCreditForm">
          {{ showCreditForm ? 'Cancel' : '+ Issue Credit Note' }}
        </button>
      </div>

      <form v-if="showCreditForm" class="mb-4 grid grid-cols-1 items-end gap-3 rounded-lg border border-border bg-surface-50 p-4 sm:grid-cols-4" @submit.prevent="submitCreditNote">
        <FormInput v-model="creditForm.credit_date" name="credit_date" type="date" label="Date" :error="creditForm.errors.credit_date" required />
        <FormCurrencyInput v-model="creditForm.amount" name="amount" label="Amount" :error="creditForm.errors.amount" required />
        <FormSearchableSelect v-model="creditForm.revenue_account_id" name="revenue_account_id" label="Revenue Account" :options="revenueAccounts" :error="creditForm.errors.revenue_account_id" required />
        <FormInput v-model="creditForm.reason" name="reason" label="Reason" :error="creditForm.errors.reason" />
        <div class="sm:col-span-4 flex justify-end">
          <PrimaryButton type="submit" :disabled="creditForm.processing">Issue &amp; Post Credit Note</PrimaryButton>
        </div>
      </form>

      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Credit Note #</th>
              <th class="py-3 px-4">Date</th>
              <th class="py-3 px-4 text-right">Amount</th>
              <th class="py-3 px-4">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="c in invoice.credit_notes" :key="c.id" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-mono font-medium text-ink-900">{{ c.credit_note_no }}</td>
              <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(c.credit_date) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(c.amount) }}</td>
              <td class="py-3 px-4"><StatusBadge :status="c.status" /></td>
            </tr>
            <tr v-if="!invoice.credit_notes.length">
              <td colspan="4" class="py-6 text-center text-ink-500">No credit notes issued for this invoice.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
