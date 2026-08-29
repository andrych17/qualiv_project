<!-- ponytail: Projects — internal-only, mirrors Legal/Matters wiring -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import PrimaryButton from '@/Components/PrimaryButton.vue'

interface ProjectRow {
  id: number
  uuid: string
  code: string
  name: string
  description: string | null
  status: string
  lead_id: number | null
  lead_name: string | null
  issues_count: number
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
  projects: PaginatedData<ProjectRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.projects.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Planning', value: 'planning' },
      { label: 'Active', value: 'active' },
      { label: 'On hold', value: 'on_hold' },
      { label: 'Completed', value: 'completed' },
      { label: 'Cancelled', value: 'cancelled' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'lead_name', label: 'Lead' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'issues_count', label: 'Issues', align: 'right' as const },
  { key: 'created_at_formatted', label: 'Created', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('projects.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ProjectRow | Record<string, unknown>) => {
  const row = item as ProjectRow
  confirm({
    title: `Delete project ${row.code}?`,
    description: 'Its issues, comments and attachments will be deleted too.',
    variant: 'destructive',
    confirmText: 'Delete project',
    onConfirm: () => router.delete(route('projects.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected project(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('projects.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Projects"
      description="Internal project & issue tracking for the Nusaevo team."
    >
      <template #actions>
        <PrimaryButton :href="route('projects.create')">New project</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="projects.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        expandable
        sticky-header
        storage-key="projects.projects"
        status-rail-key="status"
        search-placeholder="Search code or name…"
        :filter-fields="filterFields"
        export-filename="projects"
        :total="projects.total"
        :from="projects.from"
        :to="projects.to"
        :links="projects.links"
        empty-title="No projects yet"
        empty-description="Create your first project to start tracking issues."
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
          <Link :href="route('projects.show', item.id)" class="font-mono text-sm text-accent hover:underline">
            {{ item.code }}
          </Link>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-lead_name="{ item }">
          <span class="text-sm font-medium text-ink-900">{{ (item as ProjectRow).lead_name || '—' }}</span>
        </template>
        <template #cell-created_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.created_at_formatted }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('projects.edit', item.id)"
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
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-600">Description</p>
          <p class="mt-1 text-sm text-ink-900">{{ (item as ProjectRow).description || 'No description.' }}</p>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
