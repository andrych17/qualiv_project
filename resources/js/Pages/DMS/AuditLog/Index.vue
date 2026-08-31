<!-- ponytail: DMS §3I Audit Trail — tenant-wide, paginated/filterable (unlike per-document
     history, this can grow unbounded, so it gets the full DataTable, not a plain table like
     Versions.vue). Read-only: AccessLog is append-only at the model layer, so there's
     nothing here to edit or delete. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface LogRow {
  id: number
  document_id: number | null
  document_title: string | null
  action: string
  actor_name: string | null
  ip_address: string | null
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
  logs: PaginatedData<LogRow>
  filters: { search?: string; action?: string; document_id?: string; actor_id?: string; sort?: string; direction?: string; per_page?: string }
  actors: Array<{ id: number; name: string }>
  actions: string[]
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  action: props.filters.action ?? '',
  actor_id: props.filters.actor_id ?? '',
})
// Deep-linked from a document's drawer (?document_id=). Not exposed as its own filter chip —
// carried through unchanged so it survives every subsequent search/filter/sort request instead
// of silently dropping the moment the user touches anything else.
const documentId = ref(props.filters.document_id ?? '')
const documentTitle = ref(props.logs.data[0]?.document_title ?? null)
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.logs.per_page)

const label = (v: string) => v.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

const filterFields: FilterFieldDef[] = [
  { key: 'action', label: 'Action', type: 'select', options: props.actions.map((a) => ({ label: label(a), value: a })) },
  { key: 'actor_id', label: 'Actor', type: 'select', options: props.actors.map((a) => ({ label: a.name, value: String(a.id) })) },
]

const columns = [
  { key: 'created_at_formatted', label: 'When', sortable: true, sortKey: 'created_at' },
  { key: 'document_title', label: 'Document' },
  { key: 'action', label: 'Action' },
  { key: 'actor_name', label: 'Actor' },
  { key: 'ip_address', label: 'IP' },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('dms.audit-log'), {
    search: search.value,
    action: filters.value.action,
    actor_id: filters.value.actor_id,
    document_id: documentId.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const clearDocumentFilter = () => {
  documentId.value = ''
  documentTitle.value = null
  router.get(route('dms.audit-log'), { action: filters.value.action, actor_id: filters.value.actor_id }, { preserveState: true, replace: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Audit Trail" description="Every logged action across every document — upload, edit, download, restore, retention, and more.">
      <template #actions>
        <Link :href="route('dms.documents.index')" class="text-sm font-medium text-accent hover:underline">← Back to library</Link>
      </template>
    </PageHeader>

    <div
      v-if="documentId"
      class="mt-6 flex items-center justify-between rounded-md border border-border bg-surface-50 px-4 py-2 text-sm"
    >
      <span class="text-ink-700">Filtered to <span class="font-medium text-ink-900">{{ documentTitle ?? `document #${documentId}` }}</span></span>
      <button type="button" class="font-medium text-accent hover:underline" @click="clearDocumentFilter">Clear</button>
    </div>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="logs.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="dms.auditLog"
        search-placeholder="Search document or actor…"
        :filter-fields="filterFields"
        export-filename="dms-audit-log"
        :total="logs.total"
        :from="logs.from"
        :to="logs.to"
        :links="logs.links"
        empty-title="No activity yet"
        empty-description="Actions across the document library will show up here."
      >
        <template #cell-document_title="{ item }">
          <Link
            v-if="(item as LogRow).document_id"
            :href="route('dms.documents.edit', (item as LogRow).document_id!)"
            class="font-medium text-accent hover:underline"
          >
            {{ (item as LogRow).document_title ?? `#${(item as LogRow).document_id}` }}
          </Link>
          <span v-else class="text-ink-600">—</span>
        </template>
        <template #cell-action="{ item }">
          <span class="text-sm text-ink-900">{{ label((item as LogRow).action) }}</span>
        </template>
        <template #cell-actor_name="{ item }">
          <span class="text-sm text-ink-700">{{ (item as LogRow).actor_name ?? 'System' }}</span>
        </template>
        <template #cell-ip_address="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as LogRow).ip_address ?? '—' }}</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
