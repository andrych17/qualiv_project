<!-- ponytail: CRM Companies (§3C) — same underlying partners table as Contacts, filtered -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface RoleInfo {
  code: string
  name: string
}

interface CompanyRow {
  id: number
  uuid: string
  name: string
  trade_name: string | null
  industry_name: string | null
  roles?: RoleInfo[]
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
  companies: PaginatedData<CompanyRow>
  filters: { search?: string; status?: string; role?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  role: props.filters.role ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.companies.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'role',
    label: 'Kategori Partner',
    type: 'select',
    options: [
      { label: 'Semua Kategori', value: '' },
      { label: 'Customer (Klien)', value: 'customer' },
      { label: 'Vendor (Pemasok / Tech)', value: 'vendor' },
    ],
  },
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Inactive', value: 'inactive' },
    ],
  },
]

const columns = [
  { key: 'name', label: 'Legal name', sortable: true },
  { key: 'trade_name', label: 'Trade name' },
  { key: 'roles', label: 'Role / Kategori' },
  { key: 'industry_name', label: 'Industry' },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at_formatted', label: 'Added', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const setRoleFilter = (roleValue: string) => {
  filters.value.role = roleValue
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('crm.companies.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: CompanyRow | Record<string, unknown>) => {
  const row = item as CompanyRow
  confirm({
    title: `Deactivate ${row.name}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('crm.companies.destroy', row.id)),
  })
}

const confirmBulkDeactivate = () => {
  confirm({
    title: `Deactivate ${selected.value.length} selected compan(ies)?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () =>
      router.delete(route('crm.companies.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Companies"
      description="Organizations — the umbrella a contact can belong to."
    >
      <template #actions>
        <PrimaryButton :href="route('crm.companies.create')">Add company</PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="companies" class="mt-6" />

    <!-- Role Filter Tabs (UI/UX Pro Max) -->
    <div class="mt-6 flex flex-wrap items-center gap-2 border-b border-border pb-3">
      <button
        type="button"
        @click="setRoleFilter('')"
        class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
        :class="!filters.role ? 'bg-primary text-on-primary shadow-xs' : 'bg-surface-100 text-ink-600 hover:bg-surface-200 hover:text-ink-900'"
      >
        <span>Semua Organisasi</span>
      </button>
      <button
        type="button"
        @click="setRoleFilter('customer')"
        class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
        :class="filters.role === 'customer' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'"
      >
        <span class="inline-block h-2 w-2 rounded-full bg-indigo-400"></span>
        <span>Klien / Customers</span>
      </button>
      <button
        type="button"
        @click="setRoleFilter('vendor')"
        class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
        :class="filters.role === 'vendor' ? 'bg-purple-600 text-white shadow-xs' : 'bg-purple-50 text-purple-700 hover:bg-purple-100'"
      >
        <span class="inline-block h-2 w-2 rounded-full bg-purple-400"></span>
        <span>Pemasok / Tech Vendors</span>
      </button>
    </div>

    <div class="mt-4 space-y-4">
      <DataTable
        :columns="columns"
        :items="companies.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="crm.companies"
        search-placeholder="Search legal or trade name…"
        :filter-fields="filterFields"
        export-filename="crm-companies"
        :total="companies.total"
        :from="companies.from"
        :to="companies.to"
        :links="companies.links"
        empty-title="No companies found"
        empty-description="Tidak ada data perusahaan untuk filter yang dipilih."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDeactivate"
          >
            Deactivate selected
          </button>
        </template>
        <template #cell-roles="{ item }">
          <div class="flex flex-wrap gap-1">
            <template v-if="(item as CompanyRow).roles && (item as CompanyRow).roles!.length > 0">
              <span
                v-for="r in (item as CompanyRow).roles"
                :key="r.code"
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                :class="{
                  'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-700/10': r.code === 'customer',
                  'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-700/10': r.code === 'vendor',
                  'bg-surface-100 text-ink-600': r.code !== 'customer' && r.code !== 'vendor'
                }"
              >
                {{ r.name || r.code }}
              </span>
            </template>
            <span v-else class="text-xs text-ink-400 italic">Partner</span>
          </div>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as CompanyRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-created_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.created_at_formatted }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('crm.companies.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDeactivate(item)"
            >
              Deactivate
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
