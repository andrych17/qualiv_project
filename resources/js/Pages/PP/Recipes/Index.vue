<!-- ponytail: Process Recipe listing (PP_SPECS.md §3D) -->
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

interface RecipeRow {
  id: number
  product_sku: string | null
  product_name: string | null
  version: number
  batch_size: number
  uom_code: string | null
  ingredient_count: number
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
  recipes: PaginatedData<RecipeRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.recipes.per_page)

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'version', label: 'Version', sortable: true },
  { key: 'batch_size', label: 'Batch size', align: 'right' as const, sortable: true },
  { key: 'ingredient_count', label: 'Ingredients', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.recipes.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: RecipeRow | Record<string, unknown>) => {
  const row = item as RecipeRow
  confirm({
    title: `Delete recipe for ${row.product_sku} v${row.version}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.recipes.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected recipe(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.recipes.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Recipes"
      description="Process formula — batch size, expected yield/waste %, raw material ingredients. Only one active version per product."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.recipes.create')">Add recipe</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="recipes.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.recipes"
        search-placeholder="Search SKU or name…"
        export-filename="pp-recipes"
        :total="recipes.total"
        :from="recipes.from"
        :to="recipes.to"
        :links="recipes.links"
        empty-title="No recipes yet"
        empty-description="Add a recipe to enable production-type planned orders for a process item."
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
        <template #cell-product_sku="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as RecipeRow).product_sku }}</span>
        </template>
        <template #cell-batch_size="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as RecipeRow).batch_size }} {{ (item as RecipeRow).uom_code }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as RecipeRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.recipes.edit', item.id)"
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
