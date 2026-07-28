<!-- ponytail: Tenant users listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface UserRow {
  id: number
  name: string
  email: string
  groups: string[]
  created_at_formatted: string | null
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
}

type SortState = { key: string; direction: 'asc' | 'desc' } | null

const props = defineProps<{
  users: PaginatedData<UserRow>
  filters: { search?: string; sort?: string; direction?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'groups', label: 'Groups' },
  { key: 'created_at_formatted', label: 'Created', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort], debounce(() => {
  selected.value = []
  router.get(route('config.users.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const confirmDelete = (item: UserRow | Record<string, unknown>) => {
  const row = item as UserRow
  if (!confirm(`Delete user ${row.email}?`)) return
  router.delete(route('config.users.destroy', row.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected user(s)?`)) return
  router.delete(route('config.users.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Users"
      description="Manage users in this tenant and their access groups."
    >
      <template #actions>
        <Link
          :href="route('config.users.create')"
          class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800"
        >
          Create User
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <div class="w-full sm:max-w-xs">
        <SearchInput v-model="search" placeholder="Search name or email..." />
      </div>

      <DataTable
        :columns="columns"
        :items="users.data"
        v-model:sort="sort"
        v-model:selected="selected"
        selectable
        sticky-header
        storage-key="config.users"
        empty-title="No users"
        empty-description="Create a user for this tenant."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-groups="{ item }">
          <span class="text-sm text-gray-600">
            {{ (item.groups as string[]).join(', ') || '—' }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('config.users.edit', item.id)"
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

      <DataTablePagination :links="users.links" />
    </div>
  </AppLayout>
</template>
