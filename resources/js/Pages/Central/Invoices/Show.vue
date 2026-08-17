<!-- ponytail: Invoice detail + record-payment action (simplified from spec's receipt-upload
     flow — admin records the payment directly, no pending_review state) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormInput from '@/Components/forms/FormInput.vue'

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
    payments: Array<{ id: number; amount: string | number; method: string; paid_at: string; notes: string | null }>
  }
}>()

const form = useForm({
  amount: Number(props.invoice.amount_total),
  paid_at: new Date().toISOString().slice(0, 10),
  notes: '',
})

const submit = () => form.post(route('central.invoices.payments.store', props.invoice.id))
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
        <ul class="mt-4 space-y-2 text-sm">
          <li v-for="p in invoice.payments" :key="p.id" class="border-b border-gray-100 pb-2">
            {{ p.amount }} via {{ p.method }} on {{ p.paid_at }} <span v-if="p.notes" class="text-gray-500">— {{ p.notes }}</span>
          </li>
          <li v-if="!invoice.payments.length" class="text-gray-500">No payments recorded yet.</li>
        </ul>

        <form v-if="invoice.status !== 'paid' && invoice.status !== 'void'" class="mt-6 space-y-4 border-t border-gray-100 pt-4" @submit.prevent="submit">
          <h3 class="text-sm font-semibold text-gray-900">Record payment (manual bank transfer)</h3>
          <FormInput v-model.number="form.amount" name="amount" label="Amount" type="number" :error="form.errors.amount" required />
          <FormInput v-model="form.paid_at" name="paid_at" label="Paid on" type="date" :error="form.errors.paid_at" />
          <FormInput v-model="form.notes" name="notes" label="Notes" :error="form.errors.notes" />
          <button type="submit" :disabled="form.processing" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50">
            Mark Paid
          </button>
        </form>
      </div>
    </div>
  </CentralAdminLayout>
</template>
