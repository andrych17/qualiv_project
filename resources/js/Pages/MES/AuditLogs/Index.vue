<!-- ponytail: Digital Audit Trail, read-only (MES_SPECS.md §3U) -->
<script setup lang="ts">
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { debounce } from '@/Composables/debounce'

interface AuditLogRow {
  id: number
  subject_type: string
  subject_id: number
  action: string
  actor_name: string | null
  before_snapshot: Record<string, unknown> | null
  after_snapshot: Record<string, unknown> | null
  created_at: string | null
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
  logs: PaginatedData<AuditLogRow>
  filters: { subject_type?: string; action?: string; sort?: string; direction?: string; per_page?: string }
}>()

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.logs.per_page)

const columns = [
  { key: 'created_at', label: 'When', sortable: true },
  { key: 'subject_type', label: 'Subject' },
  { key: 'action', label: 'Action' },
  { key: 'actor_name', label: 'Actor' },
  { key: 'before_snapshot', label: 'Before' },
  { key: 'after_snapshot', label: 'After' },
]

watch([sort, perPage], debounce(() => {
  router.get(route('mes.auditLogs.index'), {
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Digital Audit Trail"
      description="Field-level change log for governance-sensitive edits (§3U) — distinct from the Production Event Ledger's business-action stream. System-written only."
    />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="logs.data"
        v-model:sort="sort"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.auditLogs"
        export-filename="mes-audit-logs"
        :total="logs.total"
        :from="logs.from"
        :to="logs.to"
        :links="logs.links"
        empty-title="No audit entries yet"
        empty-description="Entries appear here when a governance-sensitive edit is made — e.g. a process-parameter target changed, or a QC hold released."
      >
        <template #cell-subject_type="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as AuditLogRow).subject_type }} #{{ (item as AuditLogRow).subject_id }}</span>
        </template>
        <template #cell-before_snapshot="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as AuditLogRow).before_snapshot ? JSON.stringify((item as AuditLogRow).before_snapshot) : '—' }}</span>
        </template>
        <template #cell-after_snapshot="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as AuditLogRow).after_snapshot ? JSON.stringify((item as AuditLogRow).after_snapshot) : '—' }}</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
