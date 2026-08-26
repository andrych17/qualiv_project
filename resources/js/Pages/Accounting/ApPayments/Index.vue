<!-- ponytail: Accounting §3E vendor payments list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'partner_name', label: 'Vendor', sortable: true },
  { key: 'payment_date', label: 'Payment Date', sortable: true },
  { key: 'amount', label: 'Amount', sortable: true, align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
]

const filteredPayments = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.payments
  return props.payments.filter((p) => (p.partner_name ?? '').toLowerCase().includes(q))
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.ap-payments.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="AP Payments" description="Vendor payments — recording and posting happen together (the form itself is the review step).">
      <template #actions>
        <PrimaryButton :href="route('accounting.ap-payments.create', { company_id: selectedCompanyId })">
          Record Payment
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex items-center gap-3">
        <label class="text-xs font-semibold text-ink-600">Company:</label>
        <select
          :value="selectedCompanyId"
          class="rounded-md border border-border bg-surface-0 px-3 py-1.5 text-sm font-medium text-ink-900 shadow-xs focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
          @change="switchCompany"
        >
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
        </select>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredPayments"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="accounting.ap-payments"
        search-placeholder="Search vendor…"
        export-filename="ap-payments"
        status-rail-key="status"
        empty-title="No AP payments found"
        empty-description="Record disbursements made against vendor bills."
      >
        <template #cell-partner_name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as PaymentRow).partner_name ?? '—' }}</span>
        </template>

        <template #cell-payment_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as PaymentRow).payment_date) }}</span>
        </template>

        <template #cell-amount="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency((item as PaymentRow).amount) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as PaymentRow).status" />
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
