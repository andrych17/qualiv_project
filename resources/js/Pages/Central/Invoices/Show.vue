<!-- Invoice detail — tenant/admin payment submission + review (CENTRAL_SPECS.md §3F) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

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

const form = useForm<{ amount: number; paid_at: string; notes: string; receipt: File | null }>({
  amount: Number(props.invoice.amount_total),
  paid_at: new Date().toISOString().slice(0, 10),
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
      <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="font-serif text-lg font-semibold text-gray-900">Lines</h2>
        <table class="mt-4 w-full text-sm">
          <tbody>
            <tr v-for="line in invoice.lines" :key="line.id" class="border-b border-gray-100">
              <td class="py-2 text-gray-700">{{ line.description }}</td>
              <td class="py-2 text-right font-medium text-gray-900">{{ line.amount }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td class="pt-3 font-semibold text-gray-900">Total</td>
              <td class="pt-3 text-right font-semibold text-gray-900">{{ invoice.amount_total }} {{ invoice.currency }}</td>
            </tr>
          </tfoot>
        </table>
        <p class="mt-4 text-sm text-gray-500">Status: <span class="font-medium text-gray-900">{{ invoice.status }}</span> — Due {{ invoice.due_date }}</p>
      </div>

      <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="font-serif text-lg font-semibold text-gray-900">Payments</h2>
        <ul class="mt-4 space-y-3 text-sm">
          <li v-for="p in invoice.payments" :key="p.id" class="border-b border-gray-100 pb-3">
            <div class="flex items-center justify-between">
              <span>{{ p.amount }} via {{ p.method }} on {{ p.paid_at }}</span>
              <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ p.status }}</span>
            </div>
            <p v-if="p.notes" class="mt-1 text-gray-500">{{ p.notes }}</p>
            <p v-if="p.rejection_reason" class="mt-1 text-red-600">Rejected: {{ p.rejection_reason }}</p>
            <a v-if="p.receipt_object_key" :href="route('central.payments.receipt', p.id)" class="mt-1 inline-block text-gray-900 underline" target="_blank">
              View receipt
            </a>

            <div v-if="p.status === 'pending_review'" class="mt-2 flex items-center gap-3">
              <button type="button" class="text-sm font-semibold text-green-700" @click="confirmPayment(p.id)">Confirm</button>
              <input
                v-model="rejectForm(p.id).reason"
                type="text"
                placeholder="Rejection reason"
                class="flex-1 rounded-md border border-gray-300 px-2 py-1 text-sm"
              />
              <button
                type="button"
                class="text-sm font-semibold text-red-600 disabled:opacity-50"
                :disabled="!rejectForm(p.id).reason"
                @click="rejectPayment(p.id)"
              >
                Reject
              </button>
            </div>
          </li>
          <li v-if="!invoice.payments.length" class="text-gray-500">No payments submitted yet.</li>
        </ul>

        <form v-if="canSubmitPayment()" class="mt-6 space-y-4 border-t border-gray-100 pt-4" @submit.prevent="submit">
          <h3 class="text-sm font-semibold text-gray-900">Submit payment (manual bank transfer)</h3>
          <FormInput v-model.number="form.amount" name="amount" label="Amount" type="number" :error="form.errors.amount" required />
          <FormInput v-model="form.paid_at" name="paid_at" label="Transferred on" type="date" :error="form.errors.paid_at" />
          <FormInput v-model="form.notes" name="notes" label="Notes" :error="form.errors.notes" />

          <div class="space-y-1.5">
            <label for="receipt" class="text-sm font-medium text-gray-700">Receipt (image or PDF)</label>
            <input id="receipt" type="file" accept=".jpg,.jpeg,.png,.pdf" class="block w-full text-sm" @change="onReceiptChange" />
            <p v-if="form.errors.receipt" class="text-sm text-red-600">{{ form.errors.receipt }}</p>
          </div>

          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
            Submit for review
          </button>
        </form>
      </div>
    </div>
  </CentralAdminLayout>
</template>
