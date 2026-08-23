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
  amount: '',
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
    <PageHeader :title="bill.bill_no" :description="`${bill.partner_name ?? '—'} — ${bill.issue_date} — due ${bill.due_date}`">
      <template #actions>
        <Link :href="route('accounting.ap-bills.index', { company_id: bill.company_id })" class="mr-4 text-sm font-medium text-accent hover:underline">← Back to bills</Link>
        <Link v-if="bill.status === 'draft'" :href="route('accounting.ap-bills.edit', bill.id)" class="mr-3 text-sm font-medium text-accent hover:underline">Edit</Link>
        <button v-if="bill.status === 'draft'" type="button" class="mr-3 text-sm font-medium text-signal-danger hover:underline" @click="destroy">Delete</button>
        <Link v-if="bill.open_balance > 0.005" :href="route('accounting.ap-payments.create', { company_id: bill.company_id, partner_id: bill.partner_id })" class="mr-3 text-sm font-medium text-accent hover:underline">Record payment</Link>
        <PrimaryButton v-if="bill.status === 'draft'" type="button" @click="post">Post bill</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-6">
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Status</div>
        <StatusBadge class="mt-1" :status="bill.status" />
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Gross total</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bill.currency_code }} {{ bill.total_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Withheld</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bill.withheld_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Paid</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bill.paid_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Debited</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bill.debited_amount.toFixed(2) }}</div>
      </Panel>
      <Panel class="p-4">
        <div class="text-xs uppercase text-ink-600">Open balance</div>
        <div class="mt-1 text-sm font-semibold text-ink-900">{{ bill.open_balance.toFixed(2) }}</div>
      </Panel>
    </div>

    <Panel v-if="bill.input_faktur_pajak || bill.bukti_potong" class="mt-4 p-4 text-sm space-x-6">
      <span v-if="bill.input_faktur_pajak">
        <span class="text-ink-600">Faktur Pajak:</span>
        <span class="ml-1 font-medium text-ink-900">{{ bill.input_faktur_pajak.nomor_seri_faktur }}</span>
        <StatusBadge class="ml-2" :status="bill.input_faktur_pajak.status" />
      </span>
      <span v-if="bill.bukti_potong">
        <span class="text-ink-600">Bukti Potong ({{ bill.withholding_type_label }}):</span>
        <span class="ml-1 font-medium text-ink-900">{{ bill.bukti_potong.bp_number }}</span>
        <StatusBadge class="ml-2" :status="bill.bukti_potong.status" />
      </span>
    </Panel>

    <Panel class="mt-6">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Description</th>
            <th class="py-2">Account</th>
            <th class="py-2 text-right">Qty</th>
            <th class="py-2 text-right">Unit price</th>
            <th class="py-2">Tax</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2 text-right">Tax amount</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in bill.lines" :key="i" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ l.description }}</td>
            <td class="py-2 text-ink-700">{{ l.expense_account }}</td>
            <td class="py-2 text-right text-ink-700">{{ l.qty }}</td>
            <td class="py-2 text-right text-ink-700">{{ l.unit_price.toFixed(2) }}</td>
            <td class="py-2 text-ink-700">{{ l.tax_code ?? '—' }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.line_amount.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ l.tax_amount.toFixed(2) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-border bg-surface-50 font-semibold">
            <td class="py-2" colspan="5">Subtotal / Tax / Gross</td>
            <td class="py-2 text-right" colspan="2">{{ bill.subtotal.toFixed(2) }} / {{ bill.tax_amount.toFixed(2) }} / {{ bill.total_amount.toFixed(2) }}</td>
          </tr>
        </tfoot>
      </table>
    </Panel>

    <Panel v-if="bill.status !== 'draft'" class="mt-6">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-ink-900">Debit notes</h3>
        <button v-if="bill.open_balance > 0.005" type="button" class="text-sm font-medium text-accent hover:underline" @click="showDebitForm = !showDebitForm">
          {{ showDebitForm ? 'Cancel' : 'Issue debit note' }}
        </button>
      </div>

      <form v-if="showDebitForm" class="mb-4 grid grid-cols-1 items-end gap-3 rounded-sm border border-border bg-surface-50 p-3 sm:grid-cols-5" @submit.prevent="submitDebitNote">
        <FormInput v-model="debitForm.debit_date" name="debit_date" type="date" label="Date" :error="debitForm.errors.debit_date" required />
        <FormInput v-model="debitForm.amount" name="amount" type="number" label="Amount" :error="debitForm.errors.amount" required />
        <FormSearchableSelect v-model="debitForm.expense_account_id" name="expense_account_id" label="Expense account" :options="expenseAccounts" :error="debitForm.errors.expense_account_id" required />
        <FormInput v-model="debitForm.reason" name="reason" label="Reason" :error="debitForm.errors.reason" />
        <PrimaryButton type="submit" :disabled="debitForm.processing">Issue &amp; post</PrimaryButton>
      </form>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Debit note #</th>
            <th class="py-2">Date</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in bill.debit_notes" :key="d.id" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ d.debit_note_no }}</td>
            <td class="py-2 text-ink-700">{{ d.debit_date }}</td>
            <td class="py-2 text-right text-ink-900">{{ d.amount.toFixed(2) }}</td>
            <td class="py-2"><StatusBadge :status="d.status" /></td>
          </tr>
          <tr v-if="!bill.debit_notes.length"><td colspan="4" class="py-4 text-center text-ink-600">No debit notes.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
