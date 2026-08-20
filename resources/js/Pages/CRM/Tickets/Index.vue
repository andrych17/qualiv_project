<!-- ponytail: CRM Helpdesk Tickets (§3F) — mirrors ServiceCases Index (shared list-view components) -->
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

interface TicketRow {
  id: number
  subject: string
  requester_name: string
  category_name: string | null
  priority: string
  status: string
  channel: string
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
  tickets: PaginatedData<TicketRow>
  filters: { search?: string; status?: string; priority?: string; sla_state?: string; assigned_to?: string; channel?: string; sort?: string; direction?: string; per_page?: string }
  assignees: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  status: props.filters.status ?? '',
  priority: props.filters.priority ?? '',
  sla_state: props.filters.sla_state ?? '',
  assigned_to: props.filters.assigned_to ?? '',
  channel: props.filters.channel ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.tickets.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Open', value: 'open' },
      { label: 'In progress', value: 'in_progress' },
      { label: 'Waiting on partner', value: 'waiting_on_partner' },
      { label: 'Resolved', value: 'resolved' },
      { label: 'Closed', value: 'closed' },
    ],
  },
  {
    key: 'priority',
    label: 'Priority',
    type: 'select',
    options: [
      { label: 'Low', value: 'low' },
      { label: 'Normal', value: 'normal' },
      { label: 'High', value: 'high' },
      { label: 'Urgent', value: 'urgent' },
    ],
  },
  {
    key: 'sla_state',
    label: 'SLA state',
    type: 'select',
    options: [
      { label: 'Breached', value: 'breached' },
      { label: 'Due soon', value: 'due_soon' },
      { label: 'On track', value: 'on_track' },
    ],
  },
  {
    key: 'channel',
    label: 'Channel',
    type: 'select',
    options: [
      { label: 'Email', value: 'email' },
      { label: 'Phone', value: 'phone' },
      { label: 'Web form', value: 'web_form' },
      { label: 'In-app', value: 'in_app' },
    ],
  },
  {
    key: 'assigned_to',
    label: 'Assigned to',
    type: 'select',
    options: props.assignees.map((a) => ({ label: a.name, value: String(a.id) })),
  },
]

const columns = [
  { key: 'subject', label: 'Subject', sortable: true },
  { key: 'requester_name', label: 'Requester' },
  { key: 'category_name', label: 'Category' },
  { key: 'priority', label: 'Priority' },
  { key: 'status', label: 'Status' },
  { key: 'assigned_to_name', label: 'Assigned to' },
  { key: 'sla_due_at_formatted', label: 'SLA due', sortable: true, sortKey: 'sla_due_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('crm.tickets.index'), {
    search: search.value,
    status: filters.value.status,
    priority: filters.value.priority,
    sla_state: filters.value.sla_state,
    assigned_to: filters.value.assigned_to,
    channel: filters.value.channel,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Helpdesk"
      description="Support requests, inquiries, and complaints — for any partner, or none yet."
    >
      <template #actions>
        <PrimaryButton :href="route('crm.tickets.create')">Open ticket</PrimaryButton>
      </template>
    </PageHeader>

    <CrmSubNav active="tickets" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="tickets.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        status-rail-key="sla_state"
        storage-key="crm.tickets"
        search-placeholder="Search subject…"
        :filter-fields="filterFields"
        export-filename="crm-tickets"
        :total="tickets.total"
        :from="tickets.from"
        :to="tickets.to"
        :links="tickets.links"
        empty-title="No tickets yet"
        empty-description="Open your first ticket to start tracking support requests."
      >
        <template #cell-priority="{ item }">
          <span class="text-sm capitalize text-ink-900">{{ (item as TicketRow).priority }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as TicketRow).status" />
        </template>
        <template #cell-sla_due_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.sla_due_at_formatted ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('crm.tickets.edit', item.id)"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            Open
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
