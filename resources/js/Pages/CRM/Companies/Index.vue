<!-- ponytail: CRM Companies (§3C) — same underlying partners table as Contacts, filtered -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

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

const { t } = useI18n()

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

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'role',
    label: t('crm.role_types'),
    type: 'select',
    options: [
      { label: t('common.all'), value: '' },
      { label: t('crm.customers_clients'), value: 'customer' },
      { label: t('crm.vendors_suppliers'), value: 'vendor' },
    ],
  },
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('common.all'), value: '' },
      { label: t('common.active'), value: 'active' },
      { label: t('common.inactive'), value: 'inactive' },
    ],
  },
])

const columns = computed(() => [
  { key: 'name', label: t('crm.legal_name'), sortable: true },
  { key: 'trade_name', label: t('crm.trade_name') },
  { key: 'roles', label: t('crm.role_types') },
  { key: 'industry_name', label: t('crm.industry') },
  { key: 'is_active', label: t('common.status') },
  { key: 'created_at_formatted', label: t('crm.added'), sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

const setRoleFilter = (roleValue: string) => {
  filters.value.role = roleValue
}

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('crm.companies.index'), {
    search: search.value,
    status: filters.value.status,
    role: filters.value.role,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: CompanyRow | Record<string, unknown>) => {
  const row = item as CompanyRow
  confirm({
    title: t('crm.deactivate_company_title', { name: row.name }),
    variant: 'destructive',
    confirmText: t('crm.deactivate'),
    onConfirm: () => router.delete(route('crm.companies.destroy', row.id)),
  })
}

const confirmBulkDeactivate = () => {
  confirm({
    title: t('crm.deactivate_bulk_title', { count: selected.value.length }),
    variant: 'destructive',
    confirmText: t('crm.deactivate'),
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
      :title="t('crm.companies')"
      :description="t('crm.company_desc')"
    >
      <template #actions>
        <PrimaryButton :href="route('crm.companies.create')">
          {{ t('crm.add_company') }}
        </PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="companies" class="mt-6" />

    <!-- Role Filter Tabs (UI/UX Pro Max) -->
    <div class="mt-6 flex flex-wrap items-center gap-2 border-b border-border pb-3">
      <button
        type="button"
        @click="setRoleFilter('')"
        class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer select-none"
        :class="!filters.role ? 'bg-accent text-accent-text shadow-2xs' : 'bg-surface-50 text-ink-600 hover:bg-surface-100 hover:text-ink-900 border border-border'"
      >
        <span>{{ t('crm.all_organizations') }}</span>
      </button>
      <button
        type="button"
        @click="setRoleFilter('customer')"
        class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer select-none"
        :class="filters.role === 'customer' ? 'bg-accent text-accent-text shadow-2xs' : 'bg-surface-50 text-ink-600 hover:bg-surface-100 hover:text-ink-900 border border-border'"
      >
        <span class="inline-block h-2 w-2 rounded-full bg-accent-text/60"></span>
        <span>{{ t('crm.customers_clients') }}</span>
      </button>
      <button
        type="button"
        @click="setRoleFilter('vendor')"
        class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all cursor-pointer select-none"
        :class="filters.role === 'vendor' ? 'bg-accent text-accent-text shadow-2xs' : 'bg-surface-50 text-ink-600 hover:bg-surface-100 hover:text-ink-900 border border-border'"
      >
        <span class="inline-block h-2 w-2 rounded-full bg-accent-text/60"></span>
        <span>{{ t('crm.vendors_suppliers') }}</span>
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
        :search-placeholder="t('common.search')"
        :filter-fields="filterFields"
        export-filename="crm-companies"
        :total="companies.total"
        :from="companies.from"
        :to="companies.to"
        :links="companies.links"
        :empty-title="t('common.no_records')"
        :empty-description="t('table.no_results')"
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
            @click="confirmBulkDeactivate"
          >
            {{ t('crm.deactivate') }} ({{ selected.length }})
          </button>
        </template>
        <template #cell-roles="{ item }">
          <div class="flex flex-wrap gap-1">
            <template v-if="(item as CompanyRow).roles && (item as CompanyRow).roles!.length > 0">
              <span
                v-for="r in (item as CompanyRow).roles"
                :key="r.code"
                class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold bg-accent/10 text-accent border border-accent/20"
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
              {{ t('common.edit') }}
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer"
              @click="confirmDeactivate(item)"
            >
              {{ t('crm.deactivate') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
