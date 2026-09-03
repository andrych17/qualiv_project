<!-- ponytail: Tenant-facing "Billing & Subscription" screen (CENTRAL_SPECS.md §3H) — must stay
     reachable regardless of access_status, so no module/read-only gating on this page or its
     routes. -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormInput from '@/Components/forms/FormInput.vue'

type InvoiceLine = { id: number; description: string; amount: string | number }
type Payment = { id: number; amount: string | number; status: string; submitted_at: string | null }
type Invoice = {
  id: number
  status: string
  amount_total: string | number
  currency: string
  due_date: string
  lines: InvoiceLine[]
  payments: Payment[]
}

const props = defineProps<{
  plan: { code: string; name: string; price_monthly: string | number; currency: string } | null
  entitledModules: string[]
  addons: Array<{ id: number; module_code: string; price_override: string | number | null }>
  invoices: Invoice[]
}>()

// toISOString() converts to UTC, which silently rolls the date back a full day
// for anyone east of UTC (e.g. Asia/Jakarta) — build the Y-m-d string from the
// Date's own local getters instead.
const toLocalDateString = (d: Date): string => {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const forms = new Map<number, ReturnType<typeof useForm<{ amount: number; paid_at: string; notes: string; receipt: File | null }>>>()
const paymentForm = (invoice: Invoice) => {
  if (!forms.has(invoice.id)) {
    forms.set(invoice.id, useForm({
      amount: Number(invoice.amount_total),
      paid_at: toLocalDateString(new Date()),
      notes: '',
      receipt: null,
    }))
  }
  return forms.get(invoice.id)!
}

const onReceiptChange = (invoice: Invoice, event: Event) => {
  paymentForm(invoice).receipt = (event.target as HTMLInputElement).files?.[0] ?? null
}

const submitPayment = (invoice: Invoice) =>
  paymentForm(invoice).post(route('billing.payments.store', invoice.id), { forceFormData: true })

const canSubmit = (invoice: Invoice) => invoice.status === 'issued' || invoice.status === 'overdue'
</script>

<template>
  <AppLayout>
    <PageHeader title="Billing & Subscription" description="Your firm's plan, add-ons, and invoice history." />

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel title="Current plan">
        <p v-if="plan" class="text-sm text-ink-900">
          <span class="font-semibold">{{ plan.name }}</span> ({{ plan.code }}) — {{ plan.price_monthly }} {{ plan.currency }}/mo
        </p>
        <p v-else class="text-sm text-ink-600">No plan on file.</p>

        <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Entitled modules</h3>
        <ul class="mt-2 flex flex-wrap gap-2">
          <li v-for="code in entitledModules" :key="code" class="rounded-full border border-border bg-surface-50 px-2.5 py-0.5 text-xs text-ink-700">
            {{ code }}
          </li>
        </ul>

        <h3 v-if="addons.length" class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-600">Add-ons</h3>
        <ul v-if="addons.length" class="mt-2 space-y-1 text-sm text-ink-700">
          <li v-for="addon in addons" :key="addon.id">{{ addon.module_code }}</li>
        </ul>
      </Panel>

      <Panel title="Invoices">
        <div v-for="invoice in invoices" :key="invoice.id" class="border-b border-border py-3 last:border-b-0">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-ink-900">Due {{ invoice.due_date }} — {{ invoice.amount_total }} {{ invoice.currency }}</span>
            <StatusBadge :status="invoice.status" />
          </div>

          <form v-if="canSubmit(invoice)" class="mt-3 space-y-3" @submit.prevent="submitPayment(invoice)">
            <div class="grid grid-cols-2 gap-3">
              <FormInput
                v-model.number="paymentForm(invoice).amount"
                name="amount"
                label="Amount"
                type="number"
                :error="paymentForm(invoice).errors.amount"
                required
              />
              <FormInput
                v-model="paymentForm(invoice).paid_at"
                name="paid_at"
                label="Transferred on"
                type="date"
                :error="paymentForm(invoice).errors.paid_at"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-ink-700">Receipt (image or PDF)</label>
              <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="block w-full text-sm" @change="onReceiptChange(invoice, $event)" />
              <p v-if="paymentForm(invoice).errors.receipt" class="text-sm text-signal-danger">{{ paymentForm(invoice).errors.receipt }}</p>
            </div>
            <button
              type="submit"
              :disabled="paymentForm(invoice).processing"
              class="rounded-md bg-ink-900 px-3 py-2 text-sm font-semibold text-surface-0 shadow-sm hover:opacity-90 disabled:opacity-50"
            >
              Submit payment
            </button>
          </form>
        </div>

        <p v-if="!invoices.length" class="text-sm text-ink-600">No invoices yet.</p>
      </Panel>
    </div>
  </AppLayout>
</template>
