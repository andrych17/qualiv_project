<!-- ponytail: Station listing (MES_SPECS.md §3D) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface StationRow {
  id: number
  code: string
  name: string
  work_center_label: string | null
  machine_label: string | null
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
  stations: PaginatedData<StationRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.stations.per_page)

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'work_center_label', label: 'Work Center' },
  { key: 'machine_label', label: 'Machine' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('mes.stations.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: StationRow | Record<string, unknown>) => {
  const row = item as StationRow
  confirm({
    title: `Delete station ${row.code}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.stations.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected station(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('mes.stations.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Stations"
      description="The physical spot an operator executes at — the Shop Floor UI's target."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.stations.create')">Add Station</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="stations.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="mes.stations"
        search-placeholder="Search code or name…"
        export-filename="mes-stations"
        :total="stations.total"
        :from="stations.from"
        :to="stations.to"
        :links="stations.links"
        empty-title="No stations yet"
        empty-description="Add a station under a work center or machine for operators to execute against."
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
          <span class="font-mono text-xs text-ink-900">{{ (item as StationRow).code }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.stations.edit', item.id)"
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
