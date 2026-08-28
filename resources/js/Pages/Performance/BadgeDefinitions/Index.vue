<!-- ponytail: Badge Definition listing (§3I) -->
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

interface BadgeRow {
  id: number
  name: string
  trigger_type: string
  trigger_params: { streak_length?: number } | null
  icon: string | null
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
  badges: PaginatedData<BadgeRow>
  filters: { sort?: string; direction?: string; per_page?: string }
}>()

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.badges.per_page)

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'trigger_type', label: 'Trigger', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const triggerLabel = (row: BadgeRow) => {
  const labels: Record<string, string> = {
    target_hit: 'Target hit',
    okr_completed: 'OKR completed',
    streak_on_track: `Streak on track (${row.trigger_params?.streak_length ?? '?'}x)`,
  }
  return labels[row.trigger_type] ?? row.trigger_type
}

watch([sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('performance.badgeDefinitions.index'), {
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: BadgeRow | Record<string, unknown>) => {
  const row = item as BadgeRow
  confirm({
    title: `Delete badge "${row.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.badgeDefinitions.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected badge(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('performance.badgeDefinitions.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Badge Definitions" description="What can be earned, and the rule that triggers it.">
      <template #actions>
        <PrimaryButton :href="route('performance.badgeDefinitions.create')">Add badge</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="badgeDefinitions" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="badges.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="performance.badgeDefinitions"
        export-filename="performance-badge-definitions"
        :total="badges.total"
        :from="badges.from"
        :to="badges.to"
        :links="badges.links"
        empty-title="No badges yet"
        empty-description="Add your first badge to start recognizing achievements."
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
        <template #cell-trigger_type="{ item }">
          {{ triggerLabel(item as BadgeRow) }}
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as BadgeRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.badgeDefinitions.edit', item.id)"
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
