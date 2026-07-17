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

const props = defineProps<{
  users: PaginatedData<UserRow>
  filters: { search?: string }
}>()

const search = ref(props.filters.search ?? '')

const columns = [
  { key: 'name', label: 'Name' },
  { key: 'email', label: 'Email' },
  { key: 'groups', label: 'Groups' },
  { key: 'created_at_formatted', label: 'Created' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch(search, debounce(() => {
  router.get(route('config.users.index'), { search: search.value }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const confirmDelete = (item: UserRow | Record<string, unknown>) => {
  const row = item as UserRow
  if (!confirm(`Delete user ${row.email}?`)) return
  router.delete(route('config.users.destroy', row.id))
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
        empty-title="No users"
        empty-description="Create a user for this tenant."
      >
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
