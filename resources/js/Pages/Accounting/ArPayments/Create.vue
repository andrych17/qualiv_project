<!-- ponytail: Accounting §3D record a customer payment. Default is auto-apply oldest-first
     (server-side, ArPaymentService::autoApply); "Apply manually" reveals the open-invoice
     list so the applied amount per invoice can be overridden (§3D "manually overridable"). -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type OpenInvoice = { id: number; invoice_no: string; due_date: string; open_balance: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  selectedPartnerId: number | null
  openInvoices: OpenInvoice[]
  cashAccounts: Array<{ value: number; label: string }>
}>()

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
  company_id: props.selectedCompanyId,
  partner_id: props.selectedPartnerId,
  cash_gl_account_id: props.cashAccounts[0]?.value ?? null,
  currency_code: 'IDR',
  payment_date: today,
  amount: '',
  memo: '',
})

const openInvoices = ref<OpenInvoice[]>(props.openInvoices)
const manualMode = ref(false)
const manualAmounts = ref<Record<number, string>>({})

const loadOpenInvoices = async (partnerId: number | null) => {
  openInvoices.value = []
  manualAmounts.value = {}
  if (!partnerId || !form.company_id) return
  const res = await axios.get(route('accounting.ar-payments.open-invoices'), { params: { company_id: form.company_id, partner_id: partnerId } })
  openInvoices.value = res.data
}

watch(() => form.partner_id, (partnerId) => loadOpenInvoices(partnerId))

const manualTotal = computed(() => Object.values(manualAmounts.value).reduce((sum, v) => sum + (Number(v) || 0), 0))

const submit = () => form.transform((data) => ({
  ...data,
  amount: Number(data.amount) || 0,
  applications: manualMode.value
    ? Object.entries(manualAmounts.value)
        .filter(([, v]) => Number(v) > 0)
        .map(([ar_invoice_id, applied_amount]) => ({ ar_invoice_id: Number(ar_invoice_id), applied_amount: Number(applied_amount) }))
    : null,
})).post(route('accounting.ar-payments.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Record payment" description="Posts immediately — this form is the review step, there is no separate draft/post click." />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect
            v-model="form.company_id"
            name="company_id"
            label="Company"
            :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
            :error="form.errors.company_id"
            required
          />
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Customer" api-entity="crm_partner" placeholder="Search customer..." :error="form.errors.partner_id" required />
          <FormSearchableSelect v-model="form.cash_gl_account_id" name="cash_gl_account_id" label="Cash/bank account" :options="cashAccounts" :error="form.errors.cash_gl_account_id" required />
          <FormInput v-model="form.currency_code" name="currency_code" label="Currency" :error="form.errors.currency_code" required />
          <FormInput v-model="form.payment_date" name="payment_date" type="date" label="Payment date" :error="form.errors.payment_date" required />
          <FormInput v-model="form.amount" name="amount" type="number" label="Amount" :error="form.errors.amount" required />
        </div>
        <FormInput v-model="form.memo" name="memo" label="Memo" :error="form.errors.memo" />

        <div class="border-t border-border pt-4">
          <label class="flex items-center gap-2 text-sm text-ink-900">
            <input v-model="manualMode" type="checkbox" class="rounded border-border" />
            Apply manually instead of oldest-first
          </label>

          <div v-if="openInvoices.length" class="mt-3 overflow-x-auto rounded-sm border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase text-ink-600">
                  <th class="px-3 py-2">Invoice #</th>
                  <th class="px-3 py-2">Due date</th>
                  <th class="px-3 py-2 text-right">Open balance</th>
                  <th v-if="manualMode" class="px-3 py-2 text-right">Apply</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in openInvoices" :key="inv.id" class="border-b border-border last:border-b-0">
                  <td class="px-3 py-2 text-ink-900">{{ inv.invoice_no }}</td>
                  <td class="px-3 py-2 text-ink-700">{{ inv.due_date }}</td>
                  <td class="px-3 py-2 text-right text-ink-700">{{ inv.open_balance.toFixed(2) }}</td>
                  <td v-if="manualMode" class="px-3 py-2 text-right">
                    <input v-model="manualAmounts[inv.id]" type="number" step="0.01" min="0" class="w-28 rounded-sm border border-border bg-surface-0 px-2 py-1.5 text-right text-sm" />
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="manualMode">
                <tr class="border-t border-border bg-surface-50 font-semibold">
                  <td class="px-3 py-2" colspan="3">Applied total</td>
                  <td class="px-3 py-2 text-right">{{ manualTotal.toFixed(2) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-else-if="form.partner_id" class="mt-3 text-sm text-ink-600">No open invoices for this customer.</p>
          <p v-if="(form.errors as any).applications" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).applications }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <Link
            :href="route('accounting.ar-payments.index', { company_id: form.company_id })"
            class="inline-flex items-center justify-center rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm font-semibold text-ink-900 shadow-sm transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Cancel
          </Link>
          <PrimaryButton type="submit" :disabled="form.processing">Record &amp; post payment</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
