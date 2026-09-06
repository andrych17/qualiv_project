<!-- ponytail: Schedule Events (§3C) — mirrors Schedule Tasks Index -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import ScheduleSubNav from '@/Components/schedule/ScheduleSubNav.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('status.scheduled') !== 'status.scheduled' ? t('status.scheduled') : 'Scheduled', value: 'scheduled' },
      { label: t('status.cancelled'), value: 'cancelled' },
    ],
  },
  {
    key: 'owner_id',
    label: t('schedule.owner'),
    type: 'select',
    options: props.owners.map((o) => ({ label: o.name, value: String(o.id) })),
  },
])

const columns = computed(() => [
  { key: 'title', label: t('schedule.event_title'), sortable: true },
  { key: 'owner_name', label: t('schedule.owner') },
  { key: 'location', label: t('schedule.location') },
  { key: 'status', label: t('common.status') },
  { key: 'start_at_formatted', label: t('schedule.start_time'), sortable: true, sortKey: 'start_at' },
  { key: 'end_at_formatted', label: t('schedule.end_time') },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

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
    title: t('common.confirm_delete_title'),
    description: t('common.confirm_delete_desc'),
    variant: 'destructive',
    confirmText: t('common.delete'),
    onConfirm: () => router.delete(route('schedule.events.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('schedule.events')" :description="t('schedule.events_desc')">
      <template #actions>
        <PrimaryButton :href="route('schedule.events.create')">{{ t('schedule.add_event') }}</PrimaryButton>
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
        :search-placeholder="t('schedule.search_events')"
        :filter-fields="filterFields"
        export-filename="schedule-events"
        :total="events.total"
        :from="events.from"
        :to="events.to"
        :links="events.links"
        :empty-title="t('schedule.no_events')"
        :empty-description="t('schedule.no_events_desc')"
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
              {{ t('common.edit') }}
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
