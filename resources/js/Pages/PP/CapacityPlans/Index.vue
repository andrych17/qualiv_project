<!-- ponytail: RCCP board (PP_SPECS.md §3F) — rough-cut, informational only in Phase 1 -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { formatNumber } from '@/Utils/formatters'

interface CapacityPlanRow {
  id: number
  target_label: string
  period_start: string
  period_end: string
  required_hours: number
  available_hours: number
  load_pct: number
  is_overloaded: boolean
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
  plans: PaginatedData<CapacityPlanRow>
  filters: { search?: string; period_start?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.plans.per_page)

const columns = [
  { key: 'target_label', label: 'Work Center / Group' },
  { key: 'period_start', label: 'Period', sortable: true },
  { key: 'required_hours', label: 'Required', align: 'right' as const, sortable: true },
  { key: 'available_hours', label: 'Available', align: 'right' as const, sortable: true },
  { key: 'load_pct', label: 'Load' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.capacityPlans.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: CapacityPlanRow | Record<string, unknown>) => {
  const row = item as CapacityPlanRow
  confirm({
    title: `Delete capacity plan for ${row.target_label}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.capacityPlans.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected capacity plan(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.capacityPlans.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Capacity Planning (RCCP)"
      description="Rough-cut, infinite-capacity check — load vs. available is informational in Phase 1. Required/available hours are planner-entered (no MES routing or Schedule hours-aggregator exists yet to compute them automatically)."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.capacityPlans.create')">Add Capacity Plan</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="plans.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.capacityPlans"
        search-placeholder="Search…"
        export-filename="pp-capacity-plans"
        :total="plans.total"
        :from="plans.from"
        :to="plans.to"
        :links="plans.links"
        empty-title="No capacity plans yet"
        empty-description="Add a required-vs-available hours entry per work center or resource group and period."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDelete"
          >
            Delete selected
          </button>
        </template>
        <template #cell-period_start="{ item }">
          <span class="text-xs text-ink-600">{{ (item as CapacityPlanRow).period_start }} – {{ (item as CapacityPlanRow).period_end }}</span>
        </template>
        <template #cell-required_hours="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ formatNumber((item as CapacityPlanRow).required_hours) }} hr</span>
        </template>
        <template #cell-available_hours="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ formatNumber((item as CapacityPlanRow).available_hours) }} hr</span>
        </template>
        <template #cell-load_pct="{ item }">
          <div class="flex items-center gap-2">
            <span class="font-mono text-xs text-ink-900">{{ (item as CapacityPlanRow).load_pct }}%</span>
            <StatusBadge v-if="(item as CapacityPlanRow).is_overloaded" status="breach" label="Overload" />
          </div>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.capacityPlans.edit', item.id)"
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
