<!-- ponytail: Accounting §3E vendor payments list. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface PaymentRow {
  id: number
  partner_name: string | null
  payment_date: string
  amount: number
  status: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  payments: PaymentRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.ap-payments.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="AP Payments" description="Vendor payments — recording and posting happen together (the form itself is the review step).">
      <template #actions>
        <PrimaryButton :href="route('accounting.ap-payments.create', { company_id: selectedCompanyId })">Record payment</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <select
        :value="selectedCompanyId"
        class="mb-4 rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
        @change="switchCompany"
      >
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Vendor</th>
            <th class="py-2">Payment date</th>
            <th class="py-2 text-right">Amount</th>
            <th class="py-2">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in payments" :key="p.id" class="border-b border-border">
            <td class="py-2 text-ink-900">{{ p.partner_name ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ p.payment_date }}</td>
            <td class="py-2 text-right text-ink-900">{{ p.amount.toFixed(2) }}</td>
            <td class="py-2"><StatusBadge :status="p.status" /></td>
          </tr>
          <tr v-if="!payments.length"><td colspan="4" class="py-6 text-center text-ink-600">No payments recorded yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
