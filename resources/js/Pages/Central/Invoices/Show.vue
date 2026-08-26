<!-- Invoice detail — tenant/admin payment submission + review (CENTRAL_SPECS.md §3F) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

type Payment = {
  id: number
  amount: string | number
  method: string
  paid_at: string
  notes: string | null
  status: string
  receipt_object_key: string | null
  rejection_reason: string | null
}

const props = defineProps<{
  invoice: {
    id: number
    tenant: { id: string; name: string } | null
    plan_code: string
    status: string
    amount_total: string | number
    currency: string
    due_date: string
    lines: Array<{ id: number; description: string; amount: string | number }>
    payments: Payment[]
  }
}>()

const toLocalDateString = (d: Date): string => {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const form = useForm<{ amount: number | null; paid_at: string; notes: string; receipt: File | null }>({
  amount: Number(props.invoice.amount_total),
  paid_at: toLocalDateString(new Date()),
  notes: '',
  receipt: null,
})

const submit = () => form.post(route('central.invoices.payments.store', props.invoice.id), { forceFormData: true })

const onReceiptChange = (event: Event) => {
  form.receipt = (event.target as HTMLInputElement).files?.[0] ?? null
}

const canSubmitPayment = () => props.invoice.status === 'issued' || props.invoice.status === 'overdue'

const confirmPayment = (paymentId: number) => useForm({}).post(route('central.payments.confirm', paymentId))

const rejectForms = new Map<number, ReturnType<typeof useForm<{ reason: string }>>>()
const rejectForm = (paymentId: number) => {
  if (!rejectForms.has(paymentId)) {
    rejectForms.set(paymentId, useForm({ reason: '' }))
  }
  return rejectForms.get(paymentId)!
}
const rejectPayment = (paymentId: number) => rejectForm(paymentId).post(route('central.payments.reject', paymentId))
</script>

<template>
  <CentralAdminLayout>
    <PageHeader :title="`Invoice #${invoice.id}`" :description="`${invoice.tenant?.name ?? '—'} — ${invoice.plan_code}`" />

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <div class="rounded-xl border border-border bg-surface p-6 shadow-xs">
        <h2 class="font-serif text-lg font-semibold text-ink-900">Invoice Line Items</h2>
        <table class="mt-4 w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs uppercase tracking-wider text-ink-600">
              <th class="py-2">Description</th>
              <th class="py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="line in invoice.lines" :key="line.id">
              <td class="py-2.5 text-ink-800">{{ line.description }}</td>
              <td class="py-2.5 text-right font-mono font-medium text-ink-900">{{ formatCurrency(Number(line.amount), invoice.currency) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-border">
              <td class="pt-3 font-semibold text-ink-900">Total Due</td>
              <td class="pt-3 text-right font-mono font-bold text-accent">{{ formatCurrency(Number(invoice.amount_total), invoice.currency) }}</td>
            </tr>
          </tfoot>
        </table>
        <div class="mt-4 flex items-center justify-between text-sm text-ink-600 border-t border-border pt-3">
          <div class="flex items-center gap-2">
            <span>Status:</span>
            <StatusBadge :status="invoice.status" />
          </div>
          <span>Due {{ formatDate(invoice.due_date) }}</span>
        </div>
      </div>

      <div class="rounded-xl border border-border bg-surface p-6 shadow-xs">
        <h2 class="font-serif text-lg font-semibold text-ink-900">Payments &amp; Transfers</h2>
        <ul class="mt-4 space-y-3 text-sm">
          <li v-for="p in invoice.payments" :key="p.id" class="border-b border-border pb-3">
            <div class="flex items-center justify-between">
              <span class="font-medium text-ink-900">{{ formatCurrency(Number(p.amount), invoice.currency) }} via {{ p.method }}</span>
              <StatusBadge :status="p.status" />
            </div>
            <div class="mt-1 text-xs text-ink-600">Paid on {{ formatDate(p.paid_at) }}</div>
            <p v-if="p.notes" class="mt-1 text-xs text-ink-600">{{ p.notes }}</p>
            <p v-if="p.rejection_reason" class="mt-1 text-xs font-medium text-signal-danger">Rejected: {{ p.rejection_reason }}</p>
            <a v-if="p.receipt_object_key" :href="route('central.payments.receipt', p.id)" class="mt-1.5 inline-block text-xs font-semibold text-accent hover:underline" target="_blank">
              View Receipt Attachment &rarr;
            </a>

            <div v-if="p.status === 'pending_review'" class="mt-3 flex items-center gap-2">
              <button type="button" class="text-xs font-semibold text-signal-success hover:underline" @click="confirmPayment(p.id)">Confirm</button>
              <input
                v-model="rejectForm(p.id).reason"
                type="text"
                placeholder="Rejection reason..."
                class="flex-1 rounded-md border border-border px-2 py-1 text-xs"
              />
              <button
                type="button"
                class="text-xs font-semibold text-signal-danger disabled:opacity-50 hover:underline"
                :disabled="!rejectForm(p.id).reason"
                @click="rejectPayment(p.id)"
              >
                Reject
              </button>
            </div>
          </li>
          <li v-if="!invoice.payments.length" class="text-sm text-ink-500">No payments submitted yet.</li>
        </ul>

        <form v-if="canSubmitPayment()" class="mt-6 space-y-4 border-t border-border pt-4" @submit.prevent="submit">
          <h3 class="text-sm font-semibold text-ink-900">Submit Payment (Bank Transfer)</h3>
          <FormCurrencyInput v-model="form.amount" name="amount" label="Transfer Amount" :prefix="invoice.currency + ' '" :error="form.errors.amount" required />
          <FormInput v-model="form.paid_at" name="paid_at" label="Transfer Date" type="date" :error="form.errors.paid_at" />
          <FormInput v-model="form.notes" name="notes" label="Reference / Notes" placeholder="e.g. Transfer Ref #123456" :error="form.errors.notes" />

          <div class="space-y-1.5">
            <label for="receipt" class="text-xs font-semibold text-ink-700">Receipt Attachment (JPG, PNG, PDF)</label>
            <input id="receipt" type="file" accept=".jpg,.jpeg,.png,.pdf" class="block w-full text-xs text-ink-700 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-surface-100 file:text-ink-800 hover:file:bg-surface-200" @change="onReceiptChange" />
            <p v-if="form.errors.receipt" class="text-xs text-signal-danger">{{ form.errors.receipt }}</p>
          </div>

          <PrimaryButton type="submit" :disabled="form.processing">
            Submit for Review
          </PrimaryButton>
        </form>
      </div>
    </div>
  </CentralAdminLayout>
</template>
