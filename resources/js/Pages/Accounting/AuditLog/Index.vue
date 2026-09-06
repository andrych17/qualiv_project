<!-- ponytail: Accounting §3O Audit Trail — mirrors DMS's AuditLog/Index.vue (tenant-wide there,
     company-scoped here since Accounting is multi-company, see CompanyContextService). Read-only:
     AuditLog is append-only at the model layer. Master-data rows carry before/after snapshots,
     shown via DataTable's expandable row-detail slot rather than a separate page. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface LogRow {
  id: number
  action: string
  subject_type: string
  subject_id: number
  actor_name: string | null
  ip_address: string | null
  before_snapshot: Record<string, unknown> | null
  after_snapshot: Record<string, unknown> | null
  created_at_formatted: string | null
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
  companies: Array<{ id: number; legal_name: string }>
  selectedCompanyId: number | null
  logs: PaginatedData<LogRow>
  filters: { search?: string; action?: string; subject_type?: string; actor_id?: string; sort?: string; direction?: string; per_page?: string }
  actors: Array<{ id: number; name: string }>
  actions: string[]
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  action: props.filters.action ?? '',
  actor_id: props.filters.actor_id ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.logs.per_page)

const label = (v: string) => v.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
const subjectLabel = (v: string) => v.replace(/^accounting\./, '').replace(/_/g, ' ')

const filterFields: FilterFieldDef[] = [
  { key: 'action', label: 'Action', type: 'select', options: props.actions.map((a) => ({ label: label(a), value: a })) },
  { key: 'actor_id', label: 'Actor', type: 'select', options: props.actors.map((a) => ({ label: a.name, value: String(a.id) })) },
]

const columns = [
  { key: 'created_at_formatted', label: 'When', sortable: true, sortKey: 'created_at' },
  { key: 'action', label: 'Action' },
  { key: 'subject_type', label: 'Subject' },
  { key: 'actor_name', label: 'Actor' },
  { key: 'ip_address', label: 'IP' },
]

const switchCompany = (e: Event) => router.get(route('accounting.audit-log.index'), { company_id: (e.target as HTMLSelectElement).value }, { preserveState: true })

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('accounting.audit-log.index'), {
    company_id: props.selectedCompanyId,
    search: search.value,
    action: filters.value.action,
    actor_id: filters.value.actor_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader title="Audit Trail" description="Every logged action for this company — journal postings, period locking, invoice/bill/payment posting, tax document issuance, and master-data changes. Append-only." />

    <div class="mt-6 flex flex-wrap items-center gap-4">
      <select :value="selectedCompanyId" class="rounded-sm border border-border bg-surface-0 pl-3 pr-8 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 cursor-pointer" @change="switchCompany">
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.legal_name }}</option>
      </select>
    </div>

    <div class="mt-4 space-y-4">
      <DataTable
        :columns="columns"
        :items="logs.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        expandable
        sticky-header
        storage-key="accounting.auditLog"
        search-placeholder="Search actor…"
        :filter-fields="filterFields"
        export-filename="accounting-audit-log"
        :total="logs.total"
        :from="logs.from"
        :to="logs.to"
        :links="logs.links"
        empty-title="No activity yet"
        empty-description="Actions across this company's accounting data will show up here."
      >
        <template #cell-action="{ item }">
          <span class="text-sm text-ink-900">{{ label((item as LogRow).action) }}</span>
        </template>
        <template #cell-subject_type="{ item }">
          <span class="text-sm text-ink-700">{{ subjectLabel((item as LogRow).subject_type) }} #{{ (item as LogRow).subject_id }}</span>
        </template>
        <template #cell-actor_name="{ item }">
          <span class="text-sm text-ink-700">{{ (item as LogRow).actor_name ?? 'System' }}</span>
        </template>
        <template #cell-ip_address="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as LogRow).ip_address ?? '—' }}</span>
        </template>
        <template #row-detail="{ item }">
          <div v-if="(item as LogRow).before_snapshot || (item as LogRow).after_snapshot" class="grid grid-cols-1 gap-4 bg-surface-50 p-4 text-xs sm:grid-cols-2">
            <div v-if="(item as LogRow).before_snapshot">
              <div class="mb-1 font-semibold text-ink-700">Before</div>
              <pre class="overflow-x-auto rounded-sm bg-surface-0 p-2 font-mono">{{ JSON.stringify((item as LogRow).before_snapshot, null, 2) }}</pre>
            </div>
            <div v-if="(item as LogRow).after_snapshot">
              <div class="mb-1 font-semibold text-ink-700">After</div>
              <pre class="overflow-x-auto rounded-sm bg-surface-0 p-2 font-mono">{{ JSON.stringify((item as LogRow).after_snapshot, null, 2) }}</pre>
            </div>
          </div>
          <div v-else class="bg-surface-50 p-4 text-xs text-ink-600">No snapshot recorded for this action.</div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
