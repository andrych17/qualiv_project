<!-- ponytail: Process Phase set listing (MES_SPECS.md §3F) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PhaseSetRow {
  recipe_id: number
  product_sku: string | null
  product_name: string | null
  version: number | null
  phase_count: number
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
  phaseSets: PaginatedData<PhaseSetRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.phaseSets.per_page)

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product (Recipe)' },
  { key: 'version', label: 'Recipe Version' },
  { key: 'phase_count', label: 'Phases', align: 'right' as const },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  router.get(route('mes.processPhases.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PhaseSetRow | Record<string, unknown>) => {
  const row = item as PhaseSetRow
  confirm({
    title: `Delete process phases for ${row.product_sku ?? `recipe #${row.recipe_id}`}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('mes.processPhases.destroy', row.recipe_id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Process Phases"
      description="Continuous manufacturing's execution-step sequence — Routing's counterpart for a process recipe (PP owns the recipe/ingredient list)."
    >
      <template #actions>
        <PrimaryButton :href="route('mes.processPhases.create')">Add Phase Set</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="phaseSets.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:per-page="perPage"
        sticky-header
        storage-key="mes.processPhases"
        search-placeholder="Search SKU or name…"
        export-filename="mes-process-phases"
        :total="phaseSets.total"
        :from="phaseSets.from"
        :to="phaseSets.to"
        :links="phaseSets.links"
        empty-title="No process phases yet"
        empty-description="Add a phase set to sequence the execution steps for a process recipe."
      >
        <template #cell-product_sku="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as PhaseSetRow).product_sku }}</span>
        </template>
        <template #cell-version="{ item }">
          <span class="text-xs text-ink-600">v{{ (item as PhaseSetRow).version }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('mes.processPhases.edit', (item as PhaseSetRow).recipe_id)"
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
