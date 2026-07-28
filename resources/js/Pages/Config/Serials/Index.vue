<!-- ponytail: Config serials listing (netapp1 config_snums) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface SnumRow {
  id: number
  code: string
  last_cnt: number
  wrap_low: number
  wrap_high: number
  step_cnt: number
  descr: string | null
  status_code: string
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
}

type SortState = { key: string; direction: 'asc' | 'desc' } | null

const props = defineProps<{
  snums: PaginatedData<SnumRow>
  filters: { search?: string; sort?: string; direction?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'last_cnt', label: 'Last', align: 'right' as const, sortable: true },
  { key: 'wrap_low', label: 'Low', align: 'right' as const, sortable: true },
  { key: 'wrap_high', label: 'High', align: 'right' as const, sortable: true },
  { key: 'step_cnt', label: 'Step', align: 'right' as const, sortable: true },
  { key: 'descr', label: 'Description', sortable: true },
  { key: 'status_code', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort], debounce(() => {
  selected.value = []
  router.get(route('config.serials.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: SnumRow | Record<string, unknown>) => {
  const row = item as SnumRow
  if (!confirm(`Delete serial ${row.code}?`)) return
  router.delete(route('config.serials.destroy', row.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected serial(s)?`)) return
  router.delete(route('config.serials.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Serials"
      description="Document number counters (config_snums). Per-tenant — never share across firms."
    >
      <template #actions>
        <Link
          :href="route('config.serials.create')"
          class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
        >
          Create Serial
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="w-full sm:max-w-xs">
        <SearchInput v-model="search" placeholder="Search code or description…" />
      </div>

      <DataTable
        :columns="columns"
        :items="snums.data"
        v-model:sort="sort"
        v-model:selected="selected"
        selectable
        sticky-header
        storage-key="config.serials"
        empty-title="No serials"
        empty-description="Create a document number counter for this tenant."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('config.serials.edit', item.id)"
              class="text-sm font-medium text-gray-700 hover:text-gray-900"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-red-600 hover:text-red-950"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>

      <DataTablePagination :links="snums.links" />
    </div>
  </AppLayout>
</template>
