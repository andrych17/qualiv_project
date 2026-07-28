<!-- ponytail: Legal cases — Status Rail + design-system components -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, reactive, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface CaseRow {
  id: number
  uuid: string
  code: string
  title: string
  status: string
  notes: string | null
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
  cases: PaginatedData<CaseRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = reactive({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.cases.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Open', value: 'open' },
      { label: 'Pending', value: 'pending' },
      { label: 'Closed', value: 'closed' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'title', label: 'Title', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'created_at_formatted', label: 'Opened', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.cases.index'), {
    search: search.value,
    status: filters.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: CaseRow | Record<string, unknown>) => {
  const row = item as CaseRow
  if (!confirm(`Delete case ${row.code}?`)) return
  router.delete(route('legal.cases.destroy', row.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected case(s)?`)) return
  router.delete(route('legal.cases.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Legal cases"
      description="Where each matter stands — open, waiting, or closed."
    >
      <template #actions>
        <Link
          :href="route('legal.cases.create')"
          class="inline-flex items-center justify-center rounded-sm bg-accent px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-accent/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
          Open case
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="cases.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        expandable
        sticky-header
        storage-key="legal.cases"
        status-rail-key="status"
        search-placeholder="Search code or title…"
        :filter-fields="filterFields"
        export-filename="legal-cases"
        :total="cases.total"
        :from="cases.from"
        :to="cases.to"
        :links="cases.links"
        empty-title="No cases yet"
        empty-description="Open your first case to track matters for this firm."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDelete"
          >
            Delete selected
          </button>
        </template>
        <template #cell-code="{ item }">
          <span class="font-mono text-sm text-ink-900">{{ item.code }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-created_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.created_at_formatted }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('legal.cases.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
        <template #row-detail="{ item }">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Notes</p>
          <p class="mt-1 text-sm text-ink-900">{{ (item as CaseRow).notes || 'No notes for this case.' }}</p>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
