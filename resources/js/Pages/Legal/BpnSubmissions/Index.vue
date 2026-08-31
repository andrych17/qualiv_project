<!-- ponytail: read-only tracker list — actions (submit/reject/complete) live on the deed's BpnSubmissionPanel -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { formatCurrency, formatDate } from '@/Utils/formatters'

interface BpnSubmissionRow {
  id: number
  deed_id: number
  deed_number: string | null
  matter_code: string | null
  submission_type: string
  status: string
  tracking_number: string | null
  pnbp_amount: string | null
  submitted_at: string | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  submissions: PaginatedData<BpnSubmissionRow>
  filters: { status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.submissions.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Prepared', value: 'prepared' },
      { label: 'Submitted', value: 'submitted' },
      { label: 'In process', value: 'in_process' },
      { label: 'Completed', value: 'completed' },
      { label: 'Rejected', value: 'rejected' },
    ],
  },
]

const columns = [
  { key: 'deed_number', label: 'Deed' },
  { key: 'matter_code', label: 'Matter' },
  { key: 'submission_type', label: 'Type', sortable: true },
  { key: 'tracking_number', label: 'Tracking #' },
  { key: 'pnbp_amount', label: 'PNBP' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'submitted_at', label: 'Submitted', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.bpnSubmissions.index'), {
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader title="BPN Submissions" description="Land office (BPN) filings tracked per deed — balik nama, APHT registration, splits, and merges." />

    <LegalSubNav active="bpn-submissions" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="submissions.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="legal.bpn_submissions"
        status-rail-key="status"
        :filter-fields="filterFields"
        export-filename="legal-bpn-submissions"
        :total="submissions.total"
        :from="submissions.from"
        :to="submissions.to"
        :links="submissions.links"
        empty-title="No BPN submissions yet"
        empty-description="BPN submissions are created automatically once a deed is signed."
      >
        <template #cell-deed_number="{ item }">
          {{ item.deed_number ?? '—' }}
        </template>
        <template #cell-submission_type="{ item }">
          {{ item.submission_type.replace('_', ' ') }}
        </template>
        <template #cell-tracking_number="{ item }">
          {{ item.tracking_number ?? '—' }}
        </template>
        <template #cell-pnbp_amount="{ item }">
          {{ formatCurrency(item.pnbp_amount) }}
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-submitted_at="{ item }">
          {{ item.submitted_at ? formatDate(item.submitted_at) : '—' }}
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('legal.deeds.edit', item.deed_id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Open deed
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
