<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import CrmSubNav from '@/Components/crm/CrmSubNav.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

interface ServiceCaseRow {
  id: number
  subject: string
  partner_name: string | null
  category_name: string | null
  priority: string
  status: string
  assigned_to_name: string | null
  sla_due_at_formatted: string | null
  sla_state: string
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
  cases: PaginatedData<ServiceCaseRow>
  filters: { search?: string; status?: string; priority?: string; sla_state?: string; assigned_to?: string; sort?: string; direction?: string; per_page?: string }
  assignees: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  priority: props.filters.priority ?? '',
  sla_state: props.filters.sla_state ?? '',
  assigned_to: props.filters.assigned_to ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.cases.per_page)

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('status.open'), value: 'open' },
      { label: t('status.in_progress'), value: 'in_progress' },
      { label: 'Waiting on partner', value: 'waiting_on_partner' },
      { label: t('status.completed'), value: 'resolved' },
      { label: t('status.closed'), value: 'closed' },
    ],
  },
  {
    key: 'priority',
    label: t('crm.priority'),
    type: 'select',
    options: [
      { label: t('crm.priority_low'), value: 'low' },
      { label: t('crm.priority_normal'), value: 'normal' },
      { label: t('crm.priority_high'), value: 'high' },
      { label: t('crm.priority_urgent'), value: 'urgent' },
    ],
  },
  {
    key: 'sla_state',
    label: t('crm.sla_state'),
    type: 'select',
    options: [
      { label: t('wne.sla_breached'), value: 'breached' },
      { label: t('wne.sla_due_soon'), value: 'due_soon' },
      { label: t('wne.sla_on_track'), value: 'on_track' },
    ],
  },
  {
    key: 'assigned_to',
    label: t('crm.assigned_to'),
    type: 'select',
    options: props.assignees.map((a) => ({ label: a.name, value: String(a.id) })),
  },
])

const columns = computed(() => [
  { key: 'subject', label: t('crm.subject'), sortable: true },
  { key: 'partner_name', label: t('crm.partner') },
  { key: 'category_name', label: t('crm.category') },
  { key: 'priority', label: t('crm.priority') },
  { key: 'status', label: t('common.status') },
  { key: 'assigned_to_name', label: t('crm.assigned_to') },
  { key: 'sla_due_at_formatted', label: t('crm.sla_due'), sortable: true, sortKey: 'sla_due_at' },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('crm.serviceCases.index'), {
    search: search.value,
    status: filters.value.status,
    priority: filters.value.priority,
    sla_state: filters.value.sla_state,
    assigned_to: filters.value.assigned_to,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="t('crm.service_cases')"
      :description="t('crm.service_cases_desc')"
    >
      <template #actions>
        <PrimaryButton :href="route('crm.serviceCases.create')">{{ t('crm.open_case') }}</PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="service-cases" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="cases.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        status-rail-key="sla_state"
        storage-key="crm.serviceCases"
        :search-placeholder="t('common.search')"
        :filter-fields="filterFields"
        export-filename="crm-service-cases"
        :total="cases.total"
        :from="cases.from"
        :to="cases.to"
        :links="cases.links"
        :empty-title="t('crm.empty_cases_title')"
        :empty-description="t('crm.empty_cases_desc')"
      >
        <template #cell-priority="{ item }">
          <span class="text-sm capitalize text-ink-900">{{ (item as ServiceCaseRow).priority }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ServiceCaseRow).status" />
        </template>
        <template #cell-sla_due_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.sla_due_at_formatted ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('crm.serviceCases.edit', item.id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ t('common.open') }}
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
