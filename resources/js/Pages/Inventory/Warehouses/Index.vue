<!-- ponytail: Warehouse listing (§3C) -->
<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { showToast } from '@/Composables/useFlashToast'

interface WarehouseRow {
  id: number
  uuid: string
  name: string
  address: string | null
  location_count: number
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
  warehouses: PaginatedData<WarehouseRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.warehouses.per_page)

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
]

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'address', label: 'Address' },
  { key: 'location_count', label: 'Locations', align: 'right' as const },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.warehouses.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: WarehouseRow | Record<string, unknown>) => {
  const row = item as WarehouseRow
  confirm({
    title: `Delete warehouse ${row.name}?`,
    description: row.location_count ? `This warehouse has ${row.location_count} location(s) — deletion will be blocked until it's empty.` : undefined,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.warehouses.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected warehouse(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('inventory.warehouses.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}

// Blocked-delete guard surfaces as a validation error (WarehouseService throws
// ValidationException), not a flash message — same pattern as DMS's Folders/Index.vue.
const page = usePage()
watch(() => (page.props.errors as { name?: string })?.name, (message) => {
  if (message) showToast(message, 'error')
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Warehouses" description="Physical sites — each holds its own zone/aisle/bin location tree.">
      <template #actions>
        <PrimaryButton :href="route('inventory.warehouses.create')">Add warehouse</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="warehouses" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="warehouses.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.warehouses"
        search-placeholder="Search warehouse name…"
        :filter-fields="filterFields"
        export-filename="inventory-warehouses"
        :total="warehouses.total"
        :from="warehouses.from"
        :to="warehouses.to"
        :links="warehouses.links"
        empty-title="No warehouses yet"
        empty-description="Add your first warehouse to start building its location tree."
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
        <template #cell-address="{ item }">
          <span class="text-ink-600">{{ (item as WarehouseRow).address ?? '—' }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as WarehouseRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.warehouses.edit', item.id)"
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
