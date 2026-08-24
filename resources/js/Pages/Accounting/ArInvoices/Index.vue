<!-- ponytail: Accounting §3D customer invoices list. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'

interface InvoiceRow {
  id: number
  invoice_no: string
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
  invoices: InvoiceRow[]
}>()

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.ar-invoices.index'), { company_id: companyId }, { preserveState: true })
}

const clearPartnerFilter = () => router.get(route('accounting.ar-invoices.index'), { company_id: props.selectedCompanyId }, { preserveState: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="AR Invoices" description="Customer invoices — posting creates the AR journal and, for taxable lines, a Faktur Pajak.">
      <template #actions>
        <Link :href="route('accounting.recurring-ar-templates.index', { company_id: selectedCompanyId })" class="mr-4 text-sm font-medium text-accent hover:underline">Recurring templates</Link>
        <PrimaryButton :href="route('accounting.ar-invoices.create', { company_id: selectedCompanyId })">New invoice</PrimaryButton>
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
          Filtered by partner — clear
        </button>
      </div>

      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs uppercase text-ink-600">
            <th class="py-2">Invoice #</th>
            <th class="py-2">Customer</th>
            <th class="py-2">Issue date</th>
            <th class="py-2">Due date</th>
            <th class="py-2">Status</th>
            <th class="py-2 text-right">Total</th>
            <th class="py-2 text-right">Open balance</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="i in invoices"
            :key="i.id"
            class="cursor-pointer border-b border-border hover:bg-surface-50"
            @click="router.get(route('accounting.ar-invoices.show', i.id))"
          >
            <td class="py-2 font-medium text-ink-900">{{ i.invoice_no }}</td>
            <td class="py-2 text-ink-700">{{ i.partner_name ?? '—' }}</td>
            <td class="py-2 text-ink-700">{{ i.issue_date }}</td>
            <td class="py-2 text-ink-700">{{ i.due_date }}</td>
            <td class="py-2"><StatusBadge :status="i.status" /></td>
            <td class="py-2 text-right text-ink-900">{{ i.total_amount.toFixed(2) }}</td>
            <td class="py-2 text-right text-ink-900">{{ i.open_balance.toFixed(2) }}</td>
          </tr>
          <tr v-if="!invoices.length"><td colspan="7" class="py-6 text-center text-ink-600">No invoices yet.</td></tr>
        </tbody>
      </table>
    </Panel>
  </AppLayout>
</template>
