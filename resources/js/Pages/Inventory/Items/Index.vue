<!-- ponytail: Inventory item listing with search/status filters, datatable, and pagination -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
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
  meta?: any
}

type SortState = { key: string; direction: 'asc' | 'desc' } | null

const props = defineProps<{
  items: PaginatedData<InventoryItem>
  filters: {
    search?: string
    status?: string
    sort?: string
    direction?: string
  }
}>()

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])

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

watch([search, status, sort], debounce(() => {
  selected.value = []
  router.get(route('inventory.items.index'), {
    search: search.value,
    status: status.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
  }, {
    preserveState: true,
    replace: true,
  })
}, 400))

const goToEdit = (item: any) => {
  router.get(route('inventory.items.edit', item.id))
}

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
      <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
          <div class="w-full sm:max-w-xs">
            <SearchInput v-model="search" placeholder="Search by code or name..." />
          </div>
          <div class="w-full sm:max-w-[200px]">
            <FormSelect
              v-model="status"
              name="status"
              placeholder="All Status"
              :options="[
                { label: 'Active', value: 'active' },
                { label: 'Inactive', value: 'inactive' },
                { label: 'Archived', value: 'archived' }
              ]"
            />
          </div>
        </div>
      </div>

      <DataTable
        :columns="columns"
        :items="items.data"
        v-model:sort="sort"
        v-model:selected="selected"
        selectable
        sticky-header
        storage-key="inventory.items"
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

      <DataTablePagination :links="items.links" />
    </div>
  </AppLayout>
</template>
