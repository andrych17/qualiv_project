<!-- ponytail: Config groups listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface GroupRow {
  id: number
  code: string
  descr: string | null
  status_code: string
  status_label: string
  users_count: number
  rights_count: number
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
}

type SortState = { key: string; direction: 'asc' | 'desc' } | null

const props = defineProps<{
  groups: PaginatedData<GroupRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'descr', label: 'Description', sortable: true },
  { key: 'users_count', label: 'Users', align: 'right' as const },
  { key: 'rights_count', label: 'Menus', align: 'right' as const },
  { key: 'status_label', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, status, sort], debounce(() => {
  selected.value = []
  router.get(route('config.groups.index'), {
    search: search.value,
    status: status.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
  }, { preserveState: true, replace: true })
}, 400))

const confirmDelete = (item: GroupRow | Record<string, unknown>) => {
  const row = item as GroupRow
  if (!confirm(`Delete group ${row.code}?`)) return
  router.delete(route('config.groups.destroy', row.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected group(s)?`)) return
  router.delete(route('config.groups.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Groups"
      description="Access groups, menu rights, and member assignment."
    >
      <template #actions>
        <Link
          :href="route('config.groups.create')"
          class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
        >
          Create Group
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row">
        <div class="w-full sm:max-w-xs">
          <SearchInput v-model="search" placeholder="Search code or description..." />
        </div>
        <div class="w-full sm:max-w-[180px]">
          <FormSelect
            v-model="status"
            name="status"
            placeholder="All Status"
            :options="[
              { label: 'Active', value: 'A' },
              { label: 'Inactive', value: 'I' },
            ]"
          />
        </div>
      </div>

      <DataTable
        :columns="columns"
        :items="groups.data"
        v-model:sort="sort"
        v-model:selected="selected"
        selectable
        sticky-header
        storage-key="config.groups"
        empty-title="No groups"
        empty-description="Create a group to assign menu access."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-status_label="{ item }">
          <StatusBadge :status="item.status_label" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('config.groups.edit', item.id)"
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

      <DataTablePagination :links="groups.links" />
    </div>
  </AppLayout>
</template>
