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
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

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
  amount: '',
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
    <PageHeader :title="invoice.invoice_no" :description="`${invoice.partner_name ?? '—'} — ${invoice.issue_date} — due ${invoice.due_date}`">
      <template #actions>
        <Link :href="route('accounting.ar-invoices.index', { company_id: invoice.company_id })" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to invoices</Link>
        <Link v-if="invoice.status === 'draft'" :href="route('accounting.ar-invoices.edit', invoice.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
        <button v-if="invoice.status === 'draft'" type="button" class="mr-3 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <Link v-if="invoice.open_balance > 0.005" :href="route('accounting.ar-payments.create', { company_id: invoice.company_id, partner_id: invoice.partner_id })" class="mr-3 text-sm font-medium text-accent hover:underline">Record payment</Link>
        <PrimaryButton v-if="invoice.status === 'draft'" type="button" @click="post">Post invoice</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-5">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-1" :status="invoice.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Total</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ invoice.currency_code }} {{ invoice.total_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Paid</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ invoice.paid_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Credited</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ invoice.credited_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Open balance</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ invoice.open_balance.toFixed(2) }}</div>
      </Panel>
    </div>

    <Panel v-if="invoice.faktur_pajak" class="mt-4 p-4 text-sm">
      <span class="text-ink-600">Faktur Pajak:</span>
      <span class="ml-1 font-medium text-ink-900">{{ invoice.faktur_pajak.nomor_seri_faktur }}</span>
      <StatusBadge class="ml-2" :status="invoice.faktur_pajak.status" />
    </Panel>

    <Panel class="mt-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Description</th>
            <th class="py-2">Revenue account</th>
            <th class="py-2 text-right">Qty</th>
            <th class="py-2 text-right">Unit price</th>
            <th class="py-2">Tax</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2 text-right">Tax amount</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in invoice.lines" :key="i" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ l.description }}</td>
            <td class="py-2 text-ink-700">{{ l.revenue_account }}</td>
            <td class="py-2 text-right text-ink-700">{{ l.qty }}</td>
            <td class="py-2 text-right text-ink-700">{{ l.unit_price.toFixed(2) }}</td>
            <td class="py-2 text-ink-700">{{ l.tax_code ?? '—' }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.line_amount.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.tax_amount.toFixed(2) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-border bg-surface-50 font-semibold">
            <td class="py-2" colspan="5">Subtotal / Tax / Total</td>
            <td class="py-2 text-right" colspan="2">{{ invoice.subtotal.toFixed(2) }} / {{ invoice.tax_amount.toFixed(2) }} / {{ invoice.total_amount.toFixed(2) }}</td>
          </tr>
        </tfoot>
      </table>
    </Panel>

    <Panel v-if="invoice.status !== 'draft'" class="mt-6">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-ink-900">Credit notes</h3>
        <button v-if="invoice.open_balance > 0.005" type="button" class="text-sm font-medium text-accent hover:underline" @click="showCreditForm = !showCreditForm">
          {{ showCreditForm ? 'Cancel' : 'Issue credit note' }}
        </button>
      </div>

      <form v-if="showCreditForm" class="mb-4 grid grid-cols-1 items-end gap-3 rounded-sm border border-border bg-surface-50 p-3 sm:grid-cols-5" @submit.prevent="submitCreditNote">
        <FormInput v-model="creditForm.credit_date" name="credit_date" type="date" label="Date" :error="creditForm.errors.credit_date" required />
        <FormInput v-model="creditForm.amount" name="amount" type="number" label="Amount" :error="creditForm.errors.amount" required />
        <FormSearchableSelect v-model="creditForm.revenue_account_id" name="revenue_account_id" label="Revenue account" :options="revenueAccounts" :error="creditForm.errors.revenue_account_id" required />
        <FormInput v-model="creditForm.reason" name="reason" label="Reason" :error="creditForm.errors.reason" />
        <PrimaryButton type="submit" :disabled="creditForm.processing">Issue &amp; post</PrimaryButton>
      </form>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Credit note #</th>
            <th class="py-2">Date</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in invoice.credit_notes" :key="c.id" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ c.credit_note_no }}</td>
            <td class="py-2 text-ink-700">{{ c.credit_date }}</td>
            <td class="py-2 text-right text-ink-900">{{ c.amount.toFixed(2) }}</td>
            <td class="py-2"><StatusBadge :status="c.status" /></td>
          </tr>
          <tr v-if="!invoice.credit_notes.length"><td colspan="4" class="py-4 text-center text-ink-600">No credit notes.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
