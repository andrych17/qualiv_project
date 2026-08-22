<!-- ponytail: Legal matters (§3B) — Status Rail + design-system components -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface MatterRow {
  id: number
  uuid: string
  code: string
  title: string
  matter_type: string | null
  partner_name: string | null
  assignee_name: string | null
  status: string
  opened_at_formatted: string | null
  target_close_at_formatted: string | null
  notes: string | null
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
  matters: PaginatedData<MatterRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.matters.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Open', value: 'open' },
      { label: 'In progress', value: 'in_progress' },
      { label: 'On hold', value: 'on_hold' },
      { label: 'Closed', value: 'closed' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'title', label: 'Title', sortable: true },
  { key: 'matter_type', label: 'Type', sortable: true },
  { key: 'partner_name', label: 'Client' },
  { key: 'assignee_name', label: 'Assigned to' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'target_close_at_formatted', label: 'Target close', sortable: true, sortKey: 'target_close_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.matters.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: MatterRow | Record<string, unknown>) => {
  const row = item as MatterRow
  confirm({
    title: `Delete matter ${row.code}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('legal.matters.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected matter(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('legal.matters.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Matters"
      description="Every client engagement — property purchase, incorporation, estate planning."
    >
      <template #actions>
        <PrimaryButton :href="route('legal.matters.create')">Open matter</PrimaryButton>
      </template>
    </PageHeader>

    <LegalSubNav active="matters" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="matters.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        expandable
        sticky-header
        storage-key="legal.matters"
        status-rail-key="status"
        search-placeholder="Search code or title…"
        :filter-fields="filterFields"
        export-filename="legal-matters"
        :total="matters.total"
        :from="matters.from"
        :to="matters.to"
        :links="matters.links"
        empty-title="No matters yet"
        empty-description="Open your first matter to start tracking deeds for this firm."
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
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('legal.matters.edit', item.id)"
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
          <p class="mt-1 text-sm text-ink-900">{{ (item as MatterRow).notes || 'No notes for this matter.' }}</p>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
