<!-- ponytail: Field visits (§3M) — web slice; native mobile API deferred (Schedule + Sanctum not built yet) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import LegalSubNav from '@/Components/legal/LegalSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface VisitRow {
  id: number
  visit_type_name: string | null
  matter_code: string | null
  assignee_name: string | null
  status: string
  checked_in_at: string | null
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
  visits: PaginatedData<VisitRow>
  filters: { status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.visits.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Scheduled', value: 'scheduled' },
      { label: 'Checked in', value: 'checked_in' },
      { label: 'Completed', value: 'completed' },
    ],
  },
]

const columns = [
  { key: 'visit_type_name', label: 'Type' },
  { key: 'matter_code', label: 'Matter' },
  { key: 'assignee_name', label: 'Assigned to' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'checked_in_at', label: 'Checked in', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('legal.fieldVisits.index'), {
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: VisitRow | Record<string, unknown>) => {
  const row = item as VisitRow
  confirm({
    title: 'Delete this field visit?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('legal.fieldVisits.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Field Visits" description="Site surveys, BPN office runs, document pickups — the one workflow that lives away from a desk.">
      <template #actions>
        <PrimaryButton :href="route('legal.fieldVisits.create')">Schedule visit</PrimaryButton>
      </template>
    </PageHeader>

    <LegalSubNav active="field-visits" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="visits.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="legal.field_visits"
        status-rail-key="status"
        :filter-fields="filterFields"
        export-filename="legal-field-visits"
        :total="visits.total"
        :from="visits.from"
        :to="visits.to"
        :links="visits.links"
        empty-title="No field visits yet"
        empty-description="Schedule your first field visit."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('legal.fieldVisits.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Open
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
