<!-- ponytail: Work Center listing (MES_SPECS.md §3D) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface WorkCenterRow {
  id: number
  code: string
  name: string
  area_line: string | null
  type: string
  machine_count: number
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
  workCenters: PaginatedData<WorkCenterRow>
  filters: { search?: string; type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ type: props.filters.type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.workCenters.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'type',
    label: 'Type',
    type: 'select',
    options: [
      { label: 'Discrete', value: 'discrete' },
      { label: 'Process', value: 'process' },
    ],
  },
]

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'area_line', label: 'Area / Line' },
  { key: 'type', label: 'Type', sortable: true },
  { key: 'machine_count', label: 'Machines', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('mes.workCenters.index'), {
    search: search.value,
    type: filters.value.type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: WorkCenterRow | Record<string, unknown>) => {
  const row = item as WorkCenterRow
  confirm({
    title: `Delete work center ${row.code}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.workCenters.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected work center(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('mes.workCenters.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Work Centers"
      description="Plant → Area/Line → Work Center → Machine → Station. The top level of MES's equipment hierarchy."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.workCenters.create')">Add Work Center</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="workCenters.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="mes.workCenters"
        search-placeholder="Search code or name…"
        :filter-fields="filterFields"
        export-filename="mes-work-centers"
        :total="workCenters.total"
        :from="workCenters.from"
        :to="workCenters.to"
        :links="workCenters.links"
        empty-title="No work centers yet"
        empty-description="Add a work center to attach machines, routing operations, and process phases to."
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
          <span class="font-mono text-xs text-ink-900">{{ (item as WorkCenterRow).code }}</span>
        </template>
        <template #cell-type="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as WorkCenterRow).type }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.workCenters.edit', item.id)"
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
      </DataTable>
    </div>
  </AppLayout>
</template>
