<!-- ponytail: Schedule Resources (§3D) — mirrors Schedule Tasks/Events Index -->
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

interface ResourceRow {
  id: number
  name: string
  resource_type_name: string | null
  location_notes: string | null
  capacity: number | null
  is_active: boolean
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
  resources: PaginatedData<ResourceRow>
  filters: { search?: string; resource_type_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  resourceTypes: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ resource_type_id: props.filters.resource_type_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.resources.per_page)

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'resource_type_id',
    label: t('schedule.resource_type'),
    type: 'select',
    options: props.resourceTypes.map((tItem) => ({ label: tItem.name, value: String(tItem.id) })),
  },
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('common.active'), value: 'active' },
      { label: t('common.inactive'), value: 'inactive' },
    ],
  },
])

const columns = computed(() => [
  { key: 'name', label: t('schedule.resource_name'), sortable: true },
  { key: 'resource_type_name', label: t('schedule.resource_type') },
  { key: 'location_notes', label: t('schedule.location_notes') },
  { key: 'capacity', label: t('schedule.capacity') },
  { key: 'is_active', label: t('common.status') },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('schedule.resources.index'), {
    search: search.value,
    resource_type_id: filters.value.resource_type_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: ResourceRow | Record<string, unknown>) => {
  const row = item as ResourceRow
  confirm({
    title: t('schedule.deactivate_confirm', { name: row.name }),
    variant: 'destructive',
    confirmText: t('schedule.deactivate_resource'),
    onConfirm: () => router.delete(route('schedule.resources.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('schedule.resources')" :description="t('schedule.resources_desc')">
      <template #actions>
        <PrimaryButton :href="route('schedule.resources.create')">{{ t('schedule.add_resource') }}</PrimaryButton>
      </template>
    </PageHeader>

    <ScheduleSubNav active="resources" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="resources.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="schedule.resources"
        :search-placeholder="t('schedule.search_resources')"
        :filter-fields="filterFields"
        export-filename="schedule-resources"
        :total="resources.total"
        :from="resources.from"
        :to="resources.to"
        :links="resources.links"
        :empty-title="t('schedule.no_resources')"
        :empty-description="t('schedule.no_resources_desc')"
      >
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ResourceRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('schedule.resources.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ t('common.edit') }}
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDeactivate(item)"
            >
              {{ t('schedule.deactivate_resource') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
