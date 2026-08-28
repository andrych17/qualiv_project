<!-- ponytail: Put-away Rule listing (§3R). -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PutawayRuleRow {
  id: number
  warehouse_name: string | null
  condition: string
  target_location_code: string | null
  priority_order: number
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
  rules: PaginatedData<PutawayRuleRow>
  filters: { warehouse_id?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ warehouse_id: props.filters.warehouse_id ?? '', status: props.filters.status ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.rules.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'warehouse_id', label: 'Warehouse', type: 'select', options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })) },
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
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'condition', label: 'Condition' },
  { key: 'target_location_code', label: 'Target location' },
  { key: 'priority_order', label: 'Priority', sortable: true },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('inventory.putawayRules.index'), {
    warehouse_id: filters.value.warehouse_id,
    status: filters.value.status,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: PutawayRuleRow | Record<string, unknown>) => {
  const row = item as PutawayRuleRow
  confirm({
    title: `Delete this put-away rule?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.putawayRules.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected rule(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('inventory.putawayRules.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Put-away Rules" description="Default destination on Goods Receipt lines — first-matching-rule wins, always overridable.">
      <template #actions>
        <PrimaryButton :href="route('inventory.putawayRules.create')">Add rule</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="putawayRules" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="rules.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:filters="filters"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="inventory.putawayRules"
        :filter-fields="filterFields"
        export-filename="inventory-putaway-rules"
        :total="rules.total"
        :from="rules.from"
        :to="rules.to"
        :links="rules.links"
        empty-title="No put-away rules yet"
        empty-description="Add a rule so Goods Receipt lines default to a destination automatically."
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
          <StatusBadge :status="(item as PutawayRuleRow).is_active ? 'active' : 'inactive'" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.putawayRules.edit', item.id)"
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
