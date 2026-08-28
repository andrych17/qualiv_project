<!-- ponytail: Scorecard list (§3F) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ScorecardRow {
  id: number
  name: string
  subject_label: string
  period_label: string | null
  items_count: number
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

defineProps<{
  scorecards: PaginatedData<ScorecardRow>
  filters: { subject_type?: string }
}>()

const columns = [
  { key: 'name', label: 'Name' },
  { key: 'subject_label', label: 'Subject' },
  { key: 'period_label', label: 'Period' },
  { key: 'items_count', label: 'Items', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const { confirm } = useConfirm()

const confirmDelete = (item: ScorecardRow | Record<string, unknown>) => {
  const row = item as ScorecardRow
  confirm({
    title: `Delete scorecard "${row.name}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.scorecards.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Scorecards" description="Balanced-Scorecard compositions of KPIs and OKRs, weighted by perspective.">
      <template #actions>
        <PrimaryButton :href="route('performance.scorecards.create')">New scorecard</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="scorecards" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="scorecards.data"
        storage-key="performance.scorecards"
        export-filename="performance-scorecards"
        :total="scorecards.total"
        :from="scorecards.from"
        :to="scorecards.to"
        :links="scorecards.links"
        empty-title="No scorecards yet"
        empty-description="Build a scorecard to compose KPIs and OKRs into one weighted view."
      >
        <template #cell-name="{ item }">
          <Link :href="route('performance.scorecards.show', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ (item as ScorecardRow).name }}
          </Link>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.scorecards.show', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              View
            </Link>
            <Link
              :href="route('performance.scorecards.edit', item.id)"
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
