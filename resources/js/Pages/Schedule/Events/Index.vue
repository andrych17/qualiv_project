<!-- ponytail: Schedule Events (§3C) — mirrors Schedule Tasks Index -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface EventRow {
  id: number
  title: string
  owner_name: string | null
  location: string | null
  status: string
  start_at_formatted: string | null
  end_at_formatted: string | null
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
  events: PaginatedData<EventRow>
  filters: { search?: string; status?: string; owner_id?: string; sort?: string; direction?: string; per_page?: string }
  owners: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', owner_id: props.filters.owner_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.events.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Scheduled', value: 'scheduled' },
      { label: 'Cancelled', value: 'cancelled' },
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
  { key: 'location', label: 'Location' },
  { key: 'status', label: 'Status' },
  { key: 'start_at_formatted', label: 'Start', sortable: true, sortKey: 'start_at' },
  { key: 'end_at_formatted', label: 'End' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('schedule.events.index'), {
    search: search.value,
    status: filters.value.status,
    owner_id: filters.value.owner_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: EventRow | Record<string, unknown>) => {
  const row = item as EventRow
  confirm({
    title: `Delete "${row.title}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('schedule.events.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Events" description="Time-blocked meetings — usually multi-attendee.">
      <template #actions>
        <PrimaryButton :href="route('schedule.events.create')">Add event</PrimaryButton>
      </template>
    </PageHeader>

    <ScheduleSubNav active="events" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="events.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="schedule.events"
        search-placeholder="Search title…"
        :filter-fields="filterFields"
        export-filename="schedule-events"
        :total="events.total"
        :from="events.from"
        :to="events.to"
        :links="events.links"
        empty-title="No events yet"
        empty-description="Add your first event to start scheduling meetings."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as EventRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('schedule.events.edit', item.id)"
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
