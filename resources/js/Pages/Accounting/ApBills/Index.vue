<!-- ponytail: Accounting §3E vendor bills list. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface BillRow {
  id: number
  bill_no: string
  partner_name: string | null
  issue_date: string
  due_date: string
  status: string
  total_amount: number
  open_balance: number
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  selectedPartnerId: number | null
  bills: BillRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.ap-bills.index'), { company_id: companyId }, { preserveState: true })
}

const clearPartnerFilter = () => router.get(route('accounting.ap-bills.index'), { company_id: props.selectedCompanyId }, { preserveState: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="AP Bills" description="Vendor bills — posting creates the AP journal and, where applicable, an input Faktur Pajak and/or a Bukti Potong.">
      <template #actions>
        <PrimaryButton :href="route('accounting.ap-bills.create', { company_id: selectedCompanyId })">New bill</PrimaryButton>
      </template>
    </PageHeader>

    <Panel class="mt-6">
      <div class="mb-4 flex items-center gap-3">
        <select
          :value="selectedCompanyId"
          class="rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
        <button v-if="selectedPartnerId" type="button" class="text-sm font-medium text-accent hover:underline" @click="clearPartnerFilter">
          Filtered by vendor — clear
        </button>
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Bill #</th>
            <th class="py-2">Vendor</th>
            <th class="py-2">Issue date</th>
            <th class="py-2">Due date</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Total</th>
            <th class="py-2 text-right">Open balance</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="b in bills"
            :key="b.id"
            class="cursor-pointer border-b border-border hover:bg-surface-50"
            @click="router.get(route('accounting.ap-bills.show', b.id))"
          >
            <td class="py-2 font-medium text-ink-900">{{ b.bill_no }}</td>
            <td class="py-2 text-ink-700">{{ b.partner_name ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ b.issue_date }}</td>
            <td class="py-2 text-ink-700">{{ b.due_date }}</td>
            <td class="py-2"><StatusBadge :status="b.status" /></td>
            <td class="py-2 text-right text-ink-900">{{ b.total_amount.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ b.open_balance.toFixed(2) }}</td>
          </tr>
          <tr v-if="!bills.length"><td colspan="7" class="py-6 text-center text-ink-600">No bills yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
