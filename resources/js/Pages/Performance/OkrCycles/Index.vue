<!-- ponytail: OKR Cycle listing (§3E) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface CycleRow {
  id: number
  label: string
  start_date_formatted: string | null
  end_date_formatted: string | null
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
  cycles: PaginatedData<CycleRow>
  filters: { sort?: string; direction?: string; per_page?: string }
}>()

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.cycles.per_page)

const columns = [
  { key: 'label', label: 'Label', sortable: true },
  { key: 'start_date_formatted', label: 'Start', sortKey: 'start_date' },
  { key: 'end_date_formatted', label: 'End' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('performance.okrCycles.index'), {
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: CycleRow | Record<string, unknown>) => {
  const row = item as CycleRow
  confirm({
    title: `Delete cycle "${row.label}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.okrCycles.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected cycle(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('performance.okrCycles.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="OKR Cycles" description="Planning windows for Objectives, e.g. &quot;2026 Q3&quot;.">
      <template #actions>
        <PrimaryButton :href="route('performance.okrCycles.create')">Add cycle</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="okrCycles" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="cycles.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="performance.okrCycles"
        export-filename="performance-okr-cycles"
        :total="cycles.total"
        :from="cycles.from"
        :to="cycles.to"
        :links="cycles.links"
        empty-title="No cycles yet"
        empty-description="Add your first OKR cycle to start planning Objectives."
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
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as CycleRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.okrCycles.edit', item.id)"
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
