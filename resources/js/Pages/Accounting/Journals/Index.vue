<!-- ponytail: Accounting §3C General Ledger / Journal Entries — list, filterable by company/status/source. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { formatDate } from '@/Utils/formatters'

interface JournalRow {
  id: number
  journal_date: string
  memo: string | null
  source: string
  status: string
  period_no: number | null
  currency_code: string
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  journals: JournalRow[]
}>()

const search = ref('')
const filters = ref({
  status: '',
  source: '',
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
      { label: 'Reversed', value: 'reversed' },
    ],
  },
  {
    key: 'source',
    label: 'Source',
    type: 'select',
    options: [
      { label: 'Manual', value: 'manual' },
      { label: 'Sales', value: 'sales' },
      { label: 'Purchase', value: 'purchase' },
      { label: 'Payroll', value: 'payroll' },
      { label: 'Inventory', value: 'inventory' },
      { label: 'Fixed Assets', value: 'fixed_assets' },
    ],
  },
]

const columns = [
  { key: 'journal_date', label: 'Date', sortable: true },
  { key: 'memo', label: 'Memo', sortable: true },
  { key: 'source', label: 'Source', sortable: true },
  { key: 'period_no', label: 'Period' },
  { key: 'currency_code', label: 'Currency' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredJournals = computed(() => {
  return props.journals.filter((j) => {
    if (search.value) {
      const q = search.value.toLowerCase()
      if (!(j.memo ?? `journal #${j.id}`).toLowerCase().includes(q)) {
        return false
      }
    }
    if (filters.value.status && j.status !== filters.value.status) {
      return false
    }
    if (filters.value.source && j.source !== filters.value.source) {
      return false
    }
    return true
  })
})

const switchCompany = (e: Event) => {
  const companyId = (e.target as HTMLSelectElement).value
  router.get(route('accounting.journals.index'), { company_id: companyId }, { preserveState: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Journals" description="Every posting — manual today, every subledger engine later — goes through this single ledger.">
      <template #actions>
        <div class="flex items-center gap-2">
          <SecondaryButton :href="route('accounting.recurring-journal-templates.index', { company_id: selectedCompanyId })">
            Recurring Templates
          </SecondaryButton>
          <PrimaryButton :href="route('accounting.journals.create', { company_id: selectedCompanyId })">
            New Journal
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
      </div>

      <DataTable
        :columns="columns"
        :items="filteredJournals"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="accounting.journals"
        search-placeholder="Search memo or journal #…"
        :filter-fields="filterFields"
        export-filename="journal-entries"
        status-rail-key="status"
        empty-title="No journals found"
        empty-description="Create manual journals or post subledger transactions."
      >
        <template #cell-journal_date="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDate((item as JournalRow).journal_date) }}</span>
        </template>

        <template #cell-memo="{ item }">
          <Link
            :href="route('accounting.journals.show', (item as JournalRow).id)"
            class="font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as JournalRow).memo ?? `Journal #${(item as JournalRow).id}` }}
          </Link>
        </template>

        <template #cell-source="{ item }">
          <span class="text-xs capitalize text-ink-700">{{ (item as JournalRow).source.replace('_', ' ') }}</span>
        </template>

        <template #cell-period_no="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as JournalRow).period_no ?? '—' }}</span>
        </template>

        <template #cell-currency_code="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as JournalRow).currency_code }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as JournalRow).status" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <Link
              :href="route('accounting.journals.show', (item as JournalRow).id)"
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
