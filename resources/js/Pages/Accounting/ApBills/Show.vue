<!-- ponytail: Accounting §3E bill detail — draft is editable/postable/deletable; posted+
     is where payments/debit notes/input Faktur Pajak/Bukti Potong live. Debit-note issuance
     is inline here (v1 scope, no separate debit-note screens — see ApDebitNoteController docblock). -->
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
  expense_account: string
  line_amount: number
  tax_amount: number
}

interface DebitNoteRow {
  id: number
  debit_note_no: string
  debit_date: string
  amount: number
  status: string
}

const props = defineProps<{
  bill: {
    id: number
    company_id: number
    bill_no: string
    partner_id: number
    partner_name: string | null
    currency_code: string
    issue_date: string
    due_date: string
    vendor_faktur_no: string | null
    withholding_type_label: string | null
    status: string
    subtotal: number
    tax_amount: number
    withheld_amount: number
    total_amount: number
    paid_amount: number
    debited_amount: number
    open_balance: number
    journal_id: number | null
    input_faktur_pajak: { nomor_seri_faktur: string; status: string } | null
    bukti_potong: { bp_number: string; status: string } | null
    lines: LineRow[]
    debit_notes: DebitNoteRow[]
  }
  expenseAccounts: Array<{ value: number; label: string }>
}>()

const { confirm } = useConfirm()

const post = () => {
  confirm({
    title: 'Post this bill?',
    description: 'Creates the AP journal and, where applicable, an input Faktur Pajak and/or a Bukti Potong. Corrections after this point require a debit note, never an edit.',
    confirmText: 'Post',
    onConfirm: () => router.post(route('accounting.ap-bills.post', props.bill.id)),
  })
}

const destroy = () => {
  confirm({
    title: 'Delete this draft bill?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('accounting.ap-bills.destroy', props.bill.id)),
  })
}

const showDebitForm = ref(false)
const debitForm = useForm({
  ap_bill_id: props.bill.id,
  debit_date: new Date().toISOString().slice(0, 10),
  amount: null as number | null,
  reason: '',
  expense_account_id: props.expenseAccounts[0]?.value ?? null,
})

const submitDebitNote = () => debitForm.transform((data) => ({ ...data, amount: Number(data.amount) || 0 })).post(route('accounting.ap-debit-notes.store'), {
  preserveScroll: true,
  onSuccess: () => { showDebitForm.value = false },
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="bill.bill_no" :description="`${bill.partner_name ?? '—'} — ${formatDate(bill.issue_date)} — Due ${formatDate(bill.due_date)}`">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.ap-bills.index', { company_id: bill.company_id })">
            &larr; Back to Bills
          </SecondaryButton>
          <SecondaryButton v-if="bill.status === 'draft'" :href="route('accounting.ap-bills.edit', bill.id)">
            Edit
          </SecondaryButton>
          <DangerButton v-if="bill.status === 'draft'" type="button" @click="destroy">
            Delete
          </DangerButton>
          <SecondaryButton v-if="bill.open_balance > 0.005" :href="route('accounting.ap-payments.create', { company_id: bill.company_id, partner_id: bill.partner_id })">
            Record Payment
          </SecondaryButton>
          <PrimaryButton v-if="bill.status === 'draft'" type="button" @click="post">
            Post Bill
          </PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-2" :status="bill.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Gross Total</div>
        <div class="mt-2 font-mono text-sm font-bold text-ink-900">{{ formatCurrency(bill.total_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Withheld</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ formatCurrency(bill.withheld_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Paid</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ formatCurrency(bill.paid_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Debited</div>
        <div class="mt-2 font-mono text-sm font-semibold text-ink-900">{{ formatCurrency(bill.debited_amount) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs font-semibold uppercase text-ink-600">Open Balance</div>
        <div class="mt-2 font-mono text-sm font-bold" :class="bill.open_balance > 0.005 ? 'text-amber-700' : 'text-emerald-700'">
          {{ formatCurrency(bill.open_balance) }}
        </div>
      </Panel>
    </div>

    <Panel v-if="bill.input_faktur_pajak || bill.bukti_potong" class="mt-4 p-4 text-sm space-x-6">
      <span v-if="bill.input_faktur_pajak">
        <span class="text-ink-600 font-medium">Faktur Pajak:</span>
        <span class="ml-1.5 font-mono font-medium text-ink-900">{{ bill.input_faktur_pajak.nomor_seri_faktur }}</span>
        <StatusBadge class="ml-2" :status="bill.input_faktur_pajak.status" />
      </span>
      <span v-if="bill.bukti_potong">
        <span class="text-ink-600 font-medium">Bukti Potong ({{ bill.withholding_type_label }}):</span>
        <span class="ml-1.5 font-mono font-medium text-ink-900">{{ bill.bukti_potong.bp_number }}</span>
        <StatusBadge class="ml-2" :status="bill.bukti_potong.status" />
      </span>
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
            <tr v-for="(l, i) in bill.lines" :key="i" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-medium text-ink-900">{{ l.description }}</td>
              <td class="py-3 px-4 text-xs font-mono text-ink-700">{{ l.expense_account }}</td>
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
                {{ formatCurrency(bill.subtotal) }} + {{ formatCurrency(bill.tax_amount) }} = {{ formatCurrency(bill.total_amount) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </Panel>

    <Panel v-if="bill.status !== 'draft'" class="mt-6">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-ink-900">Debit Notes</h3>
        <button v-if="bill.open_balance > 0.005" type="button" class="text-xs font-semibold text-accent hover:underline" @click="showDebitForm = !showDebitForm">
          {{ showDebitForm ? 'Cancel' : '+ Issue Debit Note' }}
        </button>
      </div>

      <form v-if="showDebitForm" class="mb-4 grid grid-cols-1 items-end gap-3 rounded-lg border border-border bg-surface-50 p-4 sm:grid-cols-4" @submit.prevent="submitDebitNote">
        <FormInput v-model="debitForm.debit_date" name="debit_date" type="date" label="Date" :error="debitForm.errors.debit_date" required />
        <FormCurrencyInput v-model="debitForm.amount" name="amount" label="Amount" :error="debitForm.errors.amount" required />
        <FormSearchableSelect v-model="debitForm.expense_account_id" name="expense_account_id" label="Expense Account" :options="expenseAccounts" :error="debitForm.errors.expense_account_id" required />
        <FormInput v-model="debitForm.reason" name="reason" label="Reason" :error="debitForm.errors.reason" />
        <div class="sm:col-span-4 flex justify-end">
          <PrimaryButton type="submit" :disabled="debitForm.processing">Issue &amp; Post Debit Note</PrimaryButton>
        </div>
      </form>

      <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
              <th class="py-3 px-4">Debit Note #</th>
              <th class="py-3 px-4">Date</th>
              <th class="py-3 px-4 text-right">Amount</th>
              <th class="py-3 px-4">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-surface">
            <tr v-for="d in bill.debit_notes" :key="d.id" class="hover:bg-surface-50/75 transition-colors">
              <td class="py-3 px-4 font-mono font-medium text-ink-900">{{ d.debit_note_no }}</td>
              <td class="py-3 px-4 font-mono text-xs text-ink-700">{{ formatDate(d.debit_date) }}</td>
              <td class="py-3 px-4 text-right font-mono text-xs font-semibold text-ink-900">{{ formatCurrency(d.amount) }}</td>
              <td class="py-3 px-4"><StatusBadge :status="d.status" /></td>
            </tr>
            <tr v-if="!bill.debit_notes.length">
              <td colspan="4" class="py-6 text-center text-ink-500">No debit notes issued for this bill.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Panel>
  </AppLayout>
</template>
