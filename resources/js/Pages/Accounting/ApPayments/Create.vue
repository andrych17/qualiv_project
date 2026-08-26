<!-- ponytail: Accounting §3E record a vendor payment. Default is auto-apply oldest-due-first
     (server-side, ApPaymentService::autoApply); "Apply manually" reveals the open-bill list
     so the applied amount per bill can be overridden. -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormCurrencyInput from '@/Components/forms/FormCurrencyInput.vue'
import FormSearchableSelect from '@/Components/forms/FormSearchableSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

type OpenBill = { id: number; bill_no: string; due_date: string; open_balance: number }

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  selectedPartnerId: number | null
  openBills: OpenBill[]
  cashAccounts: Array<{ value: number; label: string }>
}>()

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
  company_id: props.selectedCompanyId,
  partner_id: props.selectedPartnerId,
  cash_gl_account_id: props.cashAccounts[0]?.value ?? null,
  currency_code: 'IDR',
  payment_date: today,
  amount: null as number | null,
  memo: '',
})

const openBills = ref<OpenBill[]>(props.openBills)
const manualMode = ref(false)
const manualAmounts = ref<Record<number, number | null>>({})

const loadOpenBills = async (partnerId: number | null) => {
  openBills.value = []
  manualAmounts.value = {}
  if (!partnerId || !form.company_id) return
  const res = await axios.get(route('accounting.ap-payments.open-bills'), { params: { company_id: form.company_id, partner_id: partnerId } })
  openBills.value = res.data
}

watch(() => form.partner_id, (partnerId) => loadOpenBills(partnerId))

const manualTotal = computed(() => Object.values(manualAmounts.value).reduce((sum: number, v) => sum + (Number(v) || 0), 0))

const submit = () => form.transform((data) => ({
  ...data,
  amount: Number(data.amount) || 0,
  applications: manualMode.value
    ? Object.entries(manualAmounts.value)
        .filter(([, v]) => Number(v) > 0)
        .map(([ap_bill_id, applied_amount]) => ({ ap_bill_id: Number(ap_bill_id), applied_amount: Number(applied_amount) }))
    : null,
})).post(route('accounting.ap-payments.store'))
</script>

<template>
  <AppLayout>
    <PageHeader title="Record Vendor Payment" description="Posts immediately — this form is the review step, there is no separate draft/post click." />

    <Panel class="mt-6 max-w-3xl">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormSearchableSelect
            v-model="form.company_id"
            name="company_id"
            label="Company"
            :options="companies.map((c) => ({ value: c.id, label: c.legal_name }))"
            :error="form.errors.company_id"
            required
          />
          <FormAsyncSearchableSelect v-model="form.partner_id" name="partner_id" label="Vendor" api-entity="crm_partner" placeholder="Search vendor..." :error="form.errors.partner_id" required />
          <FormSearchableSelect v-model="form.cash_gl_account_id" name="cash_gl_account_id" label="Disbursement Account" :options="cashAccounts" :error="form.errors.cash_gl_account_id" required />
          <FormInput v-model="form.currency_code" name="currency_code" label="Currency" :error="form.errors.currency_code" required />
          <FormInput v-model="form.payment_date" name="payment_date" type="date" label="Payment Date" :error="form.errors.payment_date" required />
          <FormCurrencyInput v-model="form.amount" name="amount" label="Payment Amount" :error="form.errors.amount" required />
        </div>
        <FormInput v-model="form.memo" name="memo" label="Memo / Reference Note" :error="form.errors.memo" />

        <div class="border-t border-border pt-4">
          <label class="flex items-center gap-2 text-sm font-medium text-ink-900">
            <input v-model="manualMode" type="checkbox" class="rounded border-border text-accent focus:ring-accent" />
            Apply manually instead of oldest-due-first
          </label>

          <div v-if="openBills.length" class="mt-4 overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-border bg-surface-50 text-left text-xs uppercase tracking-wider text-ink-600 font-semibold">
                  <th class="px-4 py-2.5">Bill #</th>
                  <th class="px-4 py-2.5">Due Date</th>
                  <th class="px-4 py-2.5 text-right">Open Balance</th>
                  <th v-if="manualMode" class="px-4 py-2.5 text-right">Apply Amount</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border bg-surface">
                <tr v-for="b in openBills" :key="b.id" class="hover:bg-surface-50/50 transition-colors">
                  <td class="px-4 py-2.5 font-mono text-xs font-medium text-ink-900">{{ b.bill_no }}</td>
                  <td class="px-4 py-2.5 font-mono text-xs text-ink-700">{{ formatDate(b.due_date) }}</td>
                  <td class="px-4 py-2.5 text-right font-mono text-xs text-ink-900">{{ formatCurrency(b.open_balance) }}</td>
                  <td v-if="manualMode" class="px-4 py-2 text-right">
                    <FormCurrencyInput v-model="manualAmounts[b.id]" :name="`applied_${b.id}`" prefix="" :decimals="2" class="w-32 inline-block" />
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="manualMode">
                <tr class="border-t-2 border-border bg-surface-100/75 font-semibold text-xs">
                  <td class="px-4 py-3 text-ink-900" colspan="3">Applied Total</td>
                  <td class="px-4 py-3 text-right font-mono text-accent font-bold">{{ formatCurrency(manualTotal) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-else-if="form.partner_id" class="mt-3 text-sm text-ink-500 italic">No open bills for this vendor.</p>
          <p v-if="(form.errors as any).applications" class="mt-2 text-sm text-signal-danger">{{ (form.errors as any).applications }}</p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-border pt-4">
          <SecondaryButton :href="route('accounting.ap-payments.index', { company_id: form.company_id })">
            Cancel
          </SecondaryButton>
          <PrimaryButton type="submit" :disabled="form.processing">Record &amp; Post Payment</PrimaryButton>
        </div>
      </form>
    </Panel>
  </AppLayout>
</template>
