<!-- ponytail: Tenant users listing -->
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import Panel from '@/Components/cards/Panel.vue'
import { ref, watch, onMounted } from 'vue'
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

const confirmResetPassword = (item: UserRow | Record<string, unknown>) => {
  const row = item as UserRow
  confirm({
    title: `Reset password for ${row.email}?`,
    description: 'A new password is generated immediately and the old one stops working.',
    variant: 'destructive',
    confirmText: 'Reset',
    onConfirm: () => router.patch(route('config.users.resetPassword', row.id)),
  })
}

// One-time reveal of an admin-provisioned password — see HandleInertiaRequests::share().
// Not persisted client-side; gone on next navigation once the flash session key clears.
const page = usePage()
const credentials = ref<{ email: string; password: string } | null>(null)
const copied = ref(false)

const checkCredentials = () => {
  const flash = page.props.flash as { credentials?: { email: string; password: string } | null }
  if (flash?.credentials) {
    credentials.value = flash.credentials
    copied.value = false
  }
}
onMounted(checkCredentials)
watch(() => page.props.flash, checkCredentials, { deep: true })

const copyPassword = () => {
  if (!credentials.value) return
  navigator.clipboard.writeText(credentials.value.password)
  copied.value = true
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Users"
      description="Manage users in this tenant and their access groups."
    >
      <template #actions>
        <PrimaryButton :href="route('config.users.create')">
          Create User
        </PrimaryButton>
      </template>
    </PageHeader>

    <Panel v-if="credentials" title="Password generated" class="mt-6">
      <p class="text-sm text-signal-success">
        Share this with <strong>{{ credentials.email }}</strong> securely (email/text) — it will not be shown again.
      </p>
      <div class="mt-3 flex items-center gap-3">
        <code class="rounded-md border border-border bg-surface-50 px-3 py-2 font-mono text-sm text-ink-900">{{ credentials.password }}</code>
        <button
          type="button"
          class="rounded-md border border-border bg-surface-0 px-3 py-2 text-sm font-medium text-ink-900 hover:bg-surface-50 cursor-pointer"
          @click="copyPassword"
        >
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
        <button
          type="button"
          class="text-sm font-medium text-ink-600 hover:text-ink-900 cursor-pointer"
          @click="credentials = null"
        >
          Dismiss
        </button>
      </div>
    </Panel>

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
          <button type="button" class="text-sm font-medium text-signal-danger hover:underline" @click="confirmBulkDeactivate">
            Deactivate selected
          </button>
        </template>
        <template #cell-groups="{ item }">
          <span class="text-sm text-ink-600">
            {{ (item.groups as string[]).join(', ') || '—' }}
          </span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="item.is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('config.users.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-ink-600 hover:text-ink-900 hover:underline cursor-pointer"
              @click="confirmResetPassword(item)"
            >
              Reset Password
            </button>
            <button
              v-if="item.is_active"
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline cursor-pointer"
              @click="confirmDeactivate(item)"
            >
              Deactivate
            </button>
            <button
              v-else
              type="button"
              class="text-sm font-medium text-signal-success hover:underline cursor-pointer"
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
