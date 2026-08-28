<!-- ponytail: Budget category → GL account mapping list (§3B) — optional, additive; a category with
     no active mapping here just falls back to manual actuals. -->
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

interface MappingRow {
  id: number
  category: string
  account_label: string | null
  company_name: string
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
  mappings: PaginatedData<MappingRow>
  filters: { category?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.category ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.mappings.per_page)

const columns = [
  { key: 'category', label: 'Category', sortable: true },
  { key: 'account_label', label: 'GL Account' },
  { key: 'company_name', label: 'Company scope' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('performance.budgetCategoryAccounts.index'), {
    category: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: MappingRow | Record<string, unknown>) => {
  const row = item as MappingRow
  confirm({
    title: `Delete mapping for "${row.category}"?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('performance.budgetCategoryAccounts.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected mapping(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('performance.budgetCategoryAccounts.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Budget GL Mapping" description="Map a budget category to a GL account so its actuals are read from Accounting instead of entered manually.">
      <template #actions>
        <PrimaryButton :href="route('performance.budgetCategoryAccounts.create')">Add mapping</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="budgetCategoryAccounts" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="mappings.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="performance.budgetCategoryAccounts"
        search-placeholder="Search category…"
        export-filename="performance-budget-category-accounts"
        :total="mappings.total"
        :from="mappings.from"
        :to="mappings.to"
        :links="mappings.links"
        empty-title="No mappings yet"
        empty-description="Budgets run entirely on manual actuals until a category is mapped here."
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
          <StatusBadge :status="(item as MappingRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('performance.budgetCategoryAccounts.edit', item.id)"
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
