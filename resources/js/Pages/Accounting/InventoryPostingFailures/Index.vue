<!-- ponytail: Accounting §3H review queue — "fails loudly and queues for review rather than
     posting to a suspense account silently" (spec rule). Retry re-attempts posting after the
     mapping/period problem behind a row is fixed. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { formatDateTime } from '@/Utils/formatters'

interface FailureRow {
  id: number
  event_type: string
  item_label: string
  subject_type: string
  subject_id: string
  reason: string
  status: 'pending' | 'resolved'
  created_at: string
  resolved_at: string | null
}

const props = defineProps<{
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  failures: FailureRow[]
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
      { label: 'Pending', value: 'pending' },
      { label: 'Resolved', value: 'resolved' },
    ],
  },
]

const columns = [
  { key: 'event_type', label: 'Event', sortable: true },
  { key: 'item_label', label: 'Item / Scope', sortable: true },
  { key: 'reason', label: 'Failure Reason' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'created_at', label: 'Created At', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const eventLabel = (t: string) => ({ goods_received: 'Goods Received', goods_issued: 'Goods Issued', stock_adjusted: 'Stock Adjusted' }[t] ?? t)

const filteredFailures = computed(() => {
  return props.failures.filter((f) => {
    if (search.value) {
      const q = search.value.toLowerCase()
      if (!eventLabel(f.event_type).toLowerCase().includes(q) && !f.item_label.toLowerCase().includes(q) && !f.reason.toLowerCase().includes(q)) {
        return false
      }
    }
    if (filters.value.status && f.status !== filters.value.status) {
      return false
    }
    return true
  })
})

const switchCompany = (e: Event) => router.get(route('accounting.inventory-posting-failures.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

const retry = (f: FailureRow) => router.post(route('accounting.inventory-posting-failures.retry', f.id), {}, { preserveScroll: true })
</script>

<template>
  <AppLayout>
    <PageHeader title="Inventory Posting Review Queue" description="Movements that could not post — no mapping, incomplete configuration, or closed fiscal period. Fix the mapping then retry.">
      <template #actions>
        <SecondaryButton :href="route('accounting.inventory-gl-mappings.index', { company_id: selectedCompanyId })">
          GL Mappings
        </SecondaryButton>
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
        :items="filteredFailures"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        sticky-header
        storage-key="accounting.inventory-posting-failures"
        search-placeholder="Search failures…"
        :filter-fields="filterFields"
        export-filename="inventory-posting-failures"
        status-rail-key="status"
        empty-title="No posting failures queued"
        empty-description="All inventory valuation postings have succeeded without errors."
      >
        <template #cell-event_type="{ item }">
          <span class="font-medium text-ink-900">{{ eventLabel((item as FailureRow).event_type) }}</span>
        </template>

        <template #cell-item_label="{ item }">
          <span class="font-medium text-ink-900">{{ (item as FailureRow).item_label }}</span>
        </template>

        <template #cell-reason="{ item }">
          <span class="text-xs text-signal-danger font-medium">{{ (item as FailureRow).reason }}</span>
        </template>

        <template #cell-status="{ item }">
          <StatusBadge :status="(item as FailureRow).status" />
        </template>

        <template #cell-created_at="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ formatDateTime((item as FailureRow).created_at) }}</span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end">
            <button
              v-if="(item as FailureRow).status === 'pending'"
              type="button"
              class="text-xs font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="retry(item as FailureRow)"
            >
              Retry Posting
            </button>
            <span v-else class="text-xs text-ink-500 font-mono">
              Resolved {{ formatDateTime((item as FailureRow).resolved_at) }}
            </span>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
