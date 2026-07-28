<!-- ponytail: Inventory item listing with search/status filters, datatable, and pagination -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'

interface InventoryItem {
  id: number
  code: string
  name: string
  category_name: string
  stock: number
  minimum_stock: number
  unit: string
  status: string
  created_at_formatted: string
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
  items: PaginatedData<InventoryItem>
  filters: {
    search?: string
    status?: string
    sort?: string
    direction?: string
    per_page?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.items.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Active', value: 'active' },
      { label: 'Inactive', value: 'inactive' },
      { label: 'Archived', value: 'archived' },
    ],
  },
]

const columns: Array<{
  key: string
  label: string
  align?: 'left' | 'center' | 'right'
  sortable?: boolean
  sortKey?: string
}> = [
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'category_name', label: 'Category' },
  { key: 'stock', label: 'Stock', align: 'right', sortable: true },
  { key: 'unit', label: 'Unit', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'created_at_formatted', label: 'Created Date', sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: 'Actions', align: 'right' },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.items.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const confirmDelete = (item: any) => {
  if (!confirm(`Are you sure you want to delete item ${item.name}?`)) return
  router.delete(route('inventory.items.destroy', item.id))
}

const confirmBulkDelete = () => {
  if (!confirm(`Delete ${selected.value.length} selected item(s)?`)) return
  router.delete(route('inventory.items.bulkDestroy'), {
    data: { ids: selected.value },
    onSuccess: () => { selected.value = [] },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Inventory Items"
      description="Manage inventory items, stock, category, and status."
    >
      <template #actions>
        <Link :href="route('inventory.items.create')" class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900">
          Create Item
        </Link>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="items.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.items"
        search-placeholder="Search by code or name..."
        :filter-fields="filterFields"
        export-filename="inventory-items"
        :total="items.total"
        :from="items.from"
        :to="items.to"
        :links="items.links"
        empty-title="No inventory items"
        empty-description="Create your first inventory item to start tracking stock."
      >
        <template #bulk-actions>
          <button type="button" class="text-sm font-medium text-red-600 hover:text-red-950" @click="confirmBulkDelete">
            Delete selected
          </button>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>

        <template #cell-stock="{ item }">
          <span :class="item.stock <= item.minimum_stock ? 'font-semibold text-red-600' : ''">
            {{ item.stock }}
          </span>
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link :href="route('inventory.items.edit', item.id)" class="text-sm font-medium text-gray-700 hover:text-gray-900">
              Edit
            </Link>
            <button @click="confirmDelete(item)" class="text-sm font-medium text-red-600 hover:text-red-950">
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
