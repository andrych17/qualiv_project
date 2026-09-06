<!-- ponytail: Inventory item listing with search/status filters, datatable, and pagination -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch, computed } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { useI18n } from '@/Composables/useI18n'

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

const { t } = useI18n()
const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.items.per_page)

const filterFields = computed<FilterFieldDef[]>(() => [
  {
    key: 'status',
    label: t('common.status'),
    type: 'select',
    options: [
      { label: t('common.active'), value: 'active' },
      { label: t('common.inactive'), value: 'inactive' },
      { label: t('legal.status_archived'), value: 'archived' },
    ],
  },
])

const columns = computed(() => [
  { key: 'code', label: t('inventory.sku'), sortable: true },
  { key: 'name', label: t('inventory.item_name'), sortable: true },
  { key: 'category_name', label: t('inventory.category') },
  { key: 'stock', label: t('inventory.stock'), align: 'right' as const, sortable: true },
  { key: 'unit', label: t('inventory.uom'), sortable: true },
  { key: 'status', label: t('common.status'), sortable: true },
  { key: 'created_at_formatted', label: t('inventory.created_date'), sortable: true, sortKey: 'created_at' },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

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

const { confirm } = useConfirm()

const confirmDelete = (item: any) => {
  confirm({
    title: t('inventory.confirm_delete_item', { name: item.name }),
    variant: 'destructive',
    confirmText: t('common.delete'),
    onConfirm: () => router.delete(route('inventory.items.destroy', item.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: t('inventory.confirm_bulk_delete_items', { count: selected.value.length }),
    variant: 'destructive',
    confirmText: t('common.delete'),
    onConfirm: () =>
      router.delete(route('inventory.items.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      :title="t('inventory.products')"
      :description="t('inventory.products_subtitle')"
    >
      <template #actions>
        <PrimaryButton :href="route('inventory.items.create')">
          {{ t('inventory.new_product') }}
        </PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="items.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        :search-placeholder="t('inventory.search_placeholder')"
        :filter-fields="filterFields"
        :empty-title="t('inventory.empty_products_title')"
        :empty-description="t('inventory.empty_products_desc')"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.items"
        export-filename="inventory-items"
        :total="items.total"
        :from="items.from"
        :to="items.to"
        :links="items.links"
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
