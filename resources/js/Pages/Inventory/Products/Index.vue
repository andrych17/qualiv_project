<!-- ponytail: Product Master list (§3B) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ProductRow {
  id: number
  uuid: string
  sku: string
  name: string
  category_name: string | null
  base_uom_code: string | null
  costing_method: string
  reorder_point: number
  is_active: boolean
  created_at_formatted: string | null
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
  products: PaginatedData<ProductRow>
  filters: { search?: string; status?: string; category_id?: string; sort?: string; direction?: string; per_page?: string }
  categories: Array<{ id: number; label: string }>
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '', category_id: props.filters.category_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.products.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Inactive', value: 'inactive' },
    ],
  },
  {
    key: 'category_id',
    label: 'Category',
    type: 'select',
    options: props.categories.map((c) => ({ label: c.label, value: String(c.id) })),
  },
]

const columns = [
  { key: 'sku', label: 'SKU', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'category_name', label: 'Category' },
  { key: 'base_uom_code', label: 'Base UoM' },
  { key: 'costing_method', label: 'Costing' },
  { key: 'reorder_point', label: 'Reorder pt', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'created_at_formatted', label: 'Added', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.products.index'), {
    search: search.value,
    status: filters.value.status,
    category_id: filters.value.category_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDeactivate = (item: ProductRow | Record<string, unknown>) => {
  const row = item as ProductRow
  confirm({
    title: `Deactivate ${row.sku}?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () => router.delete(route('inventory.products.destroy', row.id)),
  })
}

const confirmBulkDeactivate = () => {
  confirm({
    title: `Deactivate ${selected.value.length} selected product(s)?`,
    variant: 'destructive',
    confirmText: 'Deactivate',
    onConfirm: () =>
      router.delete(route('inventory.products.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Products"
      description="SKU master — costing, reorder policy, barcodes and UoMs for every item you stock."
    >
      <template #actions>
        <PrimaryButton :href="route('inventory.products.create')">Add product</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="products" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="products.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.products"
        search-placeholder="Search SKU or name…"
        :filter-fields="filterFields"
        export-filename="inventory-products"
        :total="products.total"
        :from="products.from"
        :to="products.to"
        :links="products.links"
        empty-title="No products yet"
        empty-description="Add your first product to start tracking stock."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDeactivate"
          >
            Deactivate selected
          </button>
        </template>
        <template #cell-sku="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as ProductRow).sku }}</span>
        </template>
        <template #cell-costing_method="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as ProductRow).costing_method }}</span>
        </template>
        <template #cell-reorder_point="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ProductRow).reorder_point }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ProductRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-created_at_formatted="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ item.created_at_formatted }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.products.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              Edit
            </Link>
            <button
              type="button"
              class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              @click="confirmDeactivate(item)"
            >
              Deactivate
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
