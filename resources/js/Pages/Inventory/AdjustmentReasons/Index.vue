<!-- ponytail: Adjustment Reason listing (§3G) -->
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

interface ReasonRow {
  id: number
  code: string
  name: string
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
  reasons: PaginatedData<ReasonRow>
  filters: { search?: string; status?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.reasons.per_page)

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
  { key: 'code', label: 'Code', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.adjustmentReasons.index'), {
    search: search.value,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ReasonRow | Record<string, unknown>) => {
  const row = item as ReasonRow
  confirm({
    title: `Delete reason ${row.name}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.adjustmentReasons.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected reason(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('inventory.adjustmentReasons.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Adjustment Reasons" description="Tenant-editable reason codes for stock adjustments.">
      <template #actions>
        <PrimaryButton :href="route('inventory.adjustmentReasons.create')">Add reason</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="adjustmentReasons" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="reasons.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.adjustmentReasons"
        search-placeholder="Search code or name…"
        :filter-fields="filterFields"
        export-filename="inventory-adjustment-reasons"
        :total="reasons.total"
        :from="reasons.from"
        :to="reasons.to"
        :links="reasons.links"
        empty-title="No reasons yet"
        empty-description="Add your first adjustment reason code."
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
        <template #cell-code="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as ReasonRow).code }}</span>
        </template>
        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as ReasonRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.adjustmentReasons.edit', item.id)"
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
