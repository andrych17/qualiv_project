<!-- ponytail: Item Planning Parameters listing (PP_SPECS.md §3A) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ParamRow {
  id: number
  product_sku: string | null
  product_name: string | null
  make_type: string
  safety_stock_qty: number
  lead_time_days: number
  planning_fence_days: number
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
  params: PaginatedData<ParamRow>
  filters: { search?: string; make_type?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ make_type: props.filters.make_type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.params.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'make_type',
    label: 'Make type',
    type: 'select',
    options: [
      { label: 'Make-to-Stock', value: 'mts' },
      { label: 'Make-to-Order', value: 'mto' },
    ],
  },
]

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'make_type', label: 'Make type' },
  { key: 'safety_stock_qty', label: 'Safety stock', align: 'right' as const },
  { key: 'lead_time_days', label: 'Lead time (d)', align: 'right' as const },
  { key: 'planning_fence_days', label: 'Planning fence (d)', align: 'right' as const, sortable: false },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.itemPlanningParams.index'), {
    search: search.value,
    make_type: filters.value.make_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ParamRow | Record<string, unknown>) => {
  const row = item as ParamRow
  confirm({
    title: `Delete planning parameters for ${row.product_sku}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.itemPlanningParams.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected row(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.itemPlanningParams.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Item Planning Parameters"
      description="Make-to-stock/order, lot sizing, safety stock and lead time — one row per product, driving MRP netting."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.itemPlanningParams.create')">Add parameters</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="params.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.itemPlanningParams"
        search-placeholder="Search SKU or name…"
        :filter-fields="filterFields"
        export-filename="pp-item-planning-params"
        :total="params.total"
        :from="params.from"
        :to="params.to"
        :links="params.links"
        empty-title="No planning parameters yet"
        empty-description="Add planning parameters for a product to start netting demand against it."
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
          <span class="font-mono text-xs text-ink-900">{{ (item as ParamRow).product_sku }}</span>
        </template>
        <template #cell-make_type="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as ParamRow).make_type }}</span>
        </template>
        <template #cell-safety_stock_qty="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ParamRow).safety_stock_qty }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.itemPlanningParams.edit', item.id)"
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
