<!-- ponytail: OKR Objectives (§3E) — Board (Kanban), List, and Alignment (indented tree), all fed
     from one flat, non-paginated array for the selected cycle. Alignment is built client-side by
     walking parent_okr_id chains already present in that same array — no extra request. -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import OkrKanbanBoard, { type ObjectiveItem } from '@/Components/performance/OkrKanbanBoard.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

const props = defineProps<{
  objectives: ObjectiveItem[]
  cycleId: number | null
  cycles: Array<{ id: number; label: string }>
}>()

const view = ref<'board' | 'list' | 'alignment'>('board')
const sort = ref<SortState>(null)
const cycleId = ref<number | null>(props.cycleId)

watch(cycleId, (id) => {
  router.get(route('performance.okrObjectives.index'), { cycle_id: id }, { preserveState: true })
})

const objectiveHref = (item: ObjectiveItem) => route('performance.okrObjectives.edit', item.id)

const onStatusChange = (id: number, status: string) => {
  router.patch(route('performance.okrObjectives.updateStatus', id), { status }, { preserveScroll: true })
}

const columns = [
  { key: 'objective_text', label: 'Objective' },
  { key: 'subject_label', label: 'Subject' },
  { key: 'status', label: 'Status' },
  { key: 'progress', label: 'Progress', align: 'right' as const },
  { key: 'key_results_count', label: 'KRs', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const sortedObjectives = computed(() => {
  if (!sort.value) return props.objectives
  const { key, direction } = sort.value
  return [...props.objectives].sort((a, b) => {
    const av = String((a as unknown as Record<string, unknown>)[key] ?? '')
    const bv = String((b as unknown as Record<string, unknown>)[key] ?? '')
    return direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av)
  })
})

interface TreeRow extends ObjectiveItem { depth: number }

const alignmentRows = computed<TreeRow[]>(() => {
  const byParent = new Map<number | null, ObjectiveItem[]>()
  for (const item of props.objectives) {
    const key = item.parent_okr_id ?? null
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key)!.push(item)
  }

  const rows: TreeRow[] = []
  const walk = (parentId: number | null, depth: number, seen: Set<number>) => {
    for (const item of byParent.get(parentId) ?? []) {
      if (seen.has(item.id)) continue // defensive — service-layer already blocks cycles
      rows.push({ ...item, depth })
      walk(item.id, depth + 1, new Set(seen).add(item.id))
    }
  }
  walk(null, 0, new Set())

  return rows
})

const { confirm } = useConfirm()

const confirmDelete = (item: ObjectiveItem) => {
  confirm({
    title: `Delete this objective?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.okrObjectives.destroy', item.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="OKRs" description="Objectives and Key Results, aligned across company/org unit/employee.">
      <template #actions>
        <FormSelect
          v-model="cycleId"
          name="cycle_id"
          placeholder="Select a cycle…"
          :options="cycles.map((c) => ({ label: c.label, value: c.id }))"
        />
        <PrimaryButton :href="route('performance.okrObjectives.create')">New objective</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="okrObjectives" class="mt-6" />

    <div class="mt-6">
      <Tabs v-model="view" :tabs="[{ key: 'board', label: 'Board' }, { key: 'list', label: 'List' }, { key: 'alignment', label: 'Alignment' }]" />
    </div>

    <div v-if="view === 'board'" class="mt-4">
      <OkrKanbanBoard :items="objectives" :item-href="objectiveHref" @status-change="onStatusChange" />
    </div>

    <div v-else-if="view === 'list'" class="mt-4">
      <DataTable
        :columns="columns"
        :items="sortedObjectives"
        v-model:sort="sort"
        status-rail-key="status"
        empty-title="No objectives yet"
        empty-description="Add your first Objective for this cycle."
      >
        <template #cell-objective_text="{ item }">
          <Link :href="route('performance.okrObjectives.edit', item.id)" class="text-sm font-medium text-accent hover:underline">
            {{ (item as ObjectiveItem).objective_text }}
          </Link>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ObjectiveItem).status" />
        </template>
        <template #cell-progress="{ item }">
          <span>{{ (item as ObjectiveItem).progress === null ? '—' : `${Math.round((item as ObjectiveItem).progress as number)}%` }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.okrObjectives.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDelete(item as ObjectiveItem)"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <div v-else class="mt-4 space-y-1 rounded-md border border-border bg-surface-0 p-4">
      <p v-if="alignmentRows.length === 0" class="text-sm text-ink-600">No objectives in this cycle yet.</p>
      <div
        v-for="row in alignmentRows"
        :key="row.id"
        class="flex items-center gap-3 border-b border-border py-2 last:border-b-0"
        :style="{ paddingLeft: `${row.depth * 24}px` }"
      >
        <Link :href="route('performance.okrObjectives.edit', row.id)" class="text-sm font-medium text-accent hover:underline">
          {{ row.objective_text }}
        </Link>
        <span class="text-xs text-ink-600">{{ row.subject_label }}</span>
        <StatusBadge :status="row.status" />
        <span class="text-xs text-ink-600">{{ row.progress === null ? 'No KRs' : `${Math.round(row.progress)}%` }}</span>
      </div>
    </div>
  </AppLayout>
</template>
