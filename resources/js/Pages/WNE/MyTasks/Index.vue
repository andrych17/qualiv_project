<!-- ponytail: WNE §3H — My Approvals / Task Inbox. Server-side filtered/sorted/paginated,
     mirrors CRM Tickets/Index.vue's list-page wiring. Breach-first ordering + role/team
     resolution both happen server-side (TaskInboxService) — this page just renders what
     comes back. -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import WneSubNav from '@/Components/wne/WneSubNav.vue'
import CompleteTaskModal from '@/Components/wne/CompleteTaskModal.vue'
import { debounce } from '@/Composables/debounce'

interface TaskRow {
  id: number
  step_code: string
  prompt: string
  decisions: string[]
  workflow_name: string
  subject_type: string | null
  subject_id: number | null
  status: string
  sla_state: string | null
  due_at_formatted: string | null
  started_at_formatted: string | null
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
  tasks: PaginatedData<TaskRow>
  filters: { search?: string; subject_type?: string; sla_state?: string; sort?: string; direction?: string; per_page?: string }
  subjectTypes: string[]
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({
  subject_type: props.filters.subject_type ?? '',
  sla_state: props.filters.sla_state ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.tasks.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'subject_type',
    label: 'Source module',
    type: 'select',
    options: props.subjectTypes.map((t) => ({ label: t, value: t })),
  },
  {
    key: 'sla_state',
    label: 'Urgency',
    type: 'select',
    options: [
      { label: 'Breached', value: 'breached' },
      { label: 'Due soon', value: 'due_soon' },
      { label: 'On track', value: 'on_track' },
      { label: 'No SLA', value: 'none' },
    ],
  },
]

const columns = [
  { key: 'workflow_name', label: 'Workflow' },
  { key: 'step_code', label: 'Step' },
  { key: 'subject_type', label: 'Source' },
  { key: 'status', label: 'Status' },
  { key: 'due_at_formatted', label: 'Due', sortable: true, sortKey: 'due_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('wne.my-tasks.index'), {
    search: search.value,
    subject_type: filters.value.subject_type,
    sla_state: filters.value.sla_state,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const activeTask = ref<TaskRow | null>(null)
const showModal = ref(false)
const openTask = (task: TaskRow) => {
  activeTask.value = task
  showModal.value = true
}
</script>

<template>
  <AppLayout>
    <PageHeader title="My Approvals" description="Tasks waiting on you — direct, role, or team assignment (WNE §3H)." />

    <WneSubNav active="my-tasks" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="tasks.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        status-rail-key="sla_state"
        storage-key="wne.my-tasks"
        search-placeholder="Search workflow or step…"
        :filter-fields="filterFields"
        :total="tasks.total"
        :from="tasks.from"
        :to="tasks.to"
        :links="tasks.links"
        empty-title="Nothing waiting on you"
        empty-description="Tasks assigned to you, your role, or your team will show up here."
      >
        <template #cell-subject_type="{ item }">
          <span class="text-sm text-ink-600">
            {{ (item as TaskRow).subject_type ? `${(item as TaskRow).subject_type}#${(item as TaskRow).subject_id}` : '—' }}
          </span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as TaskRow).status" />
        </template>
        <template #cell-due_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as TaskRow).due_at_formatted ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <button
            type="button"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="openTask(item as TaskRow)"
          >
            Act
          </button>
        </template>
      </DataTable>
    </div>

    <CompleteTaskModal :show="showModal" :task="activeTask" @close="showModal = false" />
  </AppLayout>
</template>
