<!-- ponytail: Tenant users listing -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface UserRow {
  id: number
  name: string
  email: string
  groups: string[]
  is_active: boolean
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
  users: PaginatedData<UserRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.users.per_page)

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'groups', label: 'Groups' },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at_formatted', label: 'Created', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('config.users.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: UserRow | Record<string, unknown>) => {
  const row = item as UserRow
  confirm({
    title: `Deactivate user ${row.email}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('config.users.destroy', row.id)),
  })
}

const confirmBulkDeactivate = () => {
  confirm({
    title: `Deactivate ${selected.value.length} selected user(s)?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () =>
      router.delete(route('config.users.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}

const activate = (item: UserRow | Record<string, unknown>) => {
  const row = item as UserRow
  router.patch(route('config.users.activate', row.id))
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
      <DataTable
        :columns="columns"
        :items="users.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="config.users"
        search-placeholder="Search name or email..."
        export-filename="config-users"
        :total="users.total"
        :from="users.from"
        :to="users.to"
        :links="users.links"
        empty-title="No users"
        empty-description="Create a user for this tenant."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDeactivate">
            Deactivate selected
          </button>
        </template>
        <template #cell-groups="{ item }">
          <span class="text-sm text-gray-600">
            {{ (item.groups as string[]).join(', ') || '—' }}
          </span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="item.is_active ? 'active' : 'inactive'" />
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
              v-if="item.is_active"
              type="button"
              class="text-sm font-medium text-red-600 hover:text-red-950"
              @click="confirmDeactivate(item)"
            >
              Deactivate
            </button>
            <button
              v-else
              type="button"
              class="text-sm font-medium text-signal-success hover:opacity-80"
              @click="activate(item)"
            >
              Activate
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
