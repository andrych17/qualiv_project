<!-- ponytail: Accounting §3D customer invoices list. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { formatCurrency, formatDate } from '@/Utils/formatters'

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

const search = ref('')
const filters = ref({
  status: '',
})
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Posted', value: 'posted' },
      { label: 'Partially Paid', value: 'partially_paid' },
      { label: 'Paid', value: 'paid' },
      { label: 'Cancelled', value: 'cancelled' },
    ],
  },
]

const columns = [
  { key: 'invoice_no', label: 'Invoice #', sortable: true },
  { key: 'partner_name', label: 'Customer', sortable: true },
  { key: 'issue_date', label: 'Issue Date', sortable: true },
  { key: 'due_date', label: 'Due Date', sortable: true },
  { key: 'total_amount', label: 'Total', sortable: true, align: 'right' as const },
  { key: 'open_balance', label: 'Open Balance', sortable: true, align: 'right' as const },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredInvoices = computed(() => {
  return props.invoices.filter((i) => {
    if (search.value) {
      const q = search.value.toLowerCase()
      if (!i.invoice_no.toLowerCase().includes(q) && !(i.partner_name ?? '').toLowerCase().includes(q)) {
        return false
      }
    }
    if (filters.value.status && i.status !== filters.value.status) {
      return false
    }
    return true
  })
})

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
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.recurring-ar-templates.index', { company_id: selectedCompanyId })">
            Recurring Templates
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.ar-invoices.create', { company_id: selectedCompanyId })">
            New Invoice
          </PrimaryButton>
        </div>
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
        <button v-if="selectedPartnerId" type="button" class="text-xs font-semibold text-accent hover:underline" @click="clearPartnerFilter">
          Filtered by customer &bull; Clear
        </button>
      </div>

      <DataTable
        :columns="columns"
        :items="filteredInvoices"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="accounting.ar-invoices"
        search-placeholder="Search invoice # or customer…"
        :filter-fields="filterFields"
        export-filename="ar-invoices"
        status-rail-key="status"
        empty-title="No AR invoices found"
        empty-description="Create customer invoices from Sales Orders or direct manual billing."
      >
        <template #cell-invoice_no="{ item }">
          <Link
            :href="route('accounting.ar-invoices.show', (item as InvoiceRow).id)"
            class="font-mono font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as InvoiceRow).invoice_no }}
          </Link>
        </template>

        <template #cell-partner_name="{ item }">
          <span class="font-medium text-ink-900">{{ (item as InvoiceRow).partner_name ?? '—' }}</span>
        </template>

        <template #cell-issue_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as InvoiceRow).issue_date) }}</span>
        </template>

        <template #cell-due_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as InvoiceRow).due_date) }}</span>
        </template>

        <template #cell-total_amount="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">
            {{ formatCurrency((item as InvoiceRow).total_amount) }}
          </span>
        </template>

        <template #cell-open_balance="{ item }">
          <span class="font-mono text-xs" :class="(item as InvoiceRow).open_balance > 0 ? 'text-amber-700 font-semibold' : 'text-ink-500'">
            {{ formatCurrency((item as InvoiceRow).open_balance) }}
          </span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as InvoiceRow).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <Link
              :href="route('accounting.ar-invoices.show', (item as InvoiceRow).id)"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              View &rarr;
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
