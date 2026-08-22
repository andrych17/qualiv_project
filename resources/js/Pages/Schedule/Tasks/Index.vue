<!-- ponytail: Schedule Tasks (§3B) — Status Rail + design-system components, mirrors CRM Contacts -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface TaskRow {
  id: number
  title: string
  owner_name: string | null
  priority: string | null
  status: string
  due_at_formatted: string | null
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
  filters: { search?: string; status?: string; priority?: string; owner_id?: string; sort?: string; direction?: string; per_page?: string }
  owners: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', priority: props.filters.priority ?? '', owner_id: props.filters.owner_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.tasks.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Open', value: 'open' },
      { label: 'In progress', value: 'in_progress' },
      { label: 'Done', value: 'done' },
      { label: 'Cancelled', value: 'cancelled' },
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
    ],
  },
  {
    key: 'owner_id',
    label: 'Owner',
    type: 'select',
    options: props.owners.map((o) => ({ label: o.name, value: String(o.id) })),
  },
]

const columns = [
  { key: 'title', label: 'Title', sortable: true },
  { key: 'owner_name', label: 'Owner' },
  { key: 'priority', label: 'Priority' },
  { key: 'status', label: 'Status' },
  { key: 'due_at_formatted', label: 'Due', sortable: true, sortKey: 'due_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('schedule.tasks.index'), {
    search: search.value,
    status: filters.value.status,
    priority: filters.value.priority,
    owner_id: filters.value.owner_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: TaskRow | Record<string, unknown>) => {
  const row = item as TaskRow
  confirm({
    title: `Delete "${row.title}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('schedule.tasks.destroy', row.id)),
  })
}

const markDoneForm = useForm({})
const markDone = (item: TaskRow) => {
  markDoneForm.post(route('schedule.tasks.markDone', item.id), { preserveScroll: true })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Tasks" description="To-dos with due dates — single-owner, no attendees required.">
      <template #actions>
        <PrimaryButton :href="route('schedule.tasks.create')">Add task</PrimaryButton>
      </template>
    </PageHeader>

    <ScheduleSubNav active="tasks" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="tasks.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="schedule.tasks"
        search-placeholder="Search title…"
        :filter-fields="filterFields"
        export-filename="schedule-tasks"
        :total="tasks.total"
        :from="tasks.from"
        :to="tasks.to"
        :links="tasks.links"
        empty-title="No tasks yet"
        empty-description="Add your first task to start tracking to-dos."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as TaskRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <button
              v-if="(item as TaskRow).status !== 'done'"
              type="button"
              class="text-sm font-medium text-signal-success hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="markDone(item as TaskRow)"
            >
              Mark done
            </button>
            <Link
              :href="route('schedule.tasks.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
