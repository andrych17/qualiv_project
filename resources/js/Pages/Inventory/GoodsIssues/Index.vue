<!-- ponytail: Goods Issue list (§3E) -->
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

interface IssueRow {
  id: number
  reason: string | null
  warehouse_name: string | null
  issue_date_formatted: string | null
  line_count: number
  status: string
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
  issues: PaginatedData<IssueRow>
  filters: { status?: string; warehouse_id?: string; sort?: string; direction?: string; per_page?: string }
  warehouses: Array<{ id: number; name: string }>
}>()

const filters = ref({ status: props.filters.status ?? '', warehouse_id: props.filters.warehouse_id ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.issues.per_page)

const filterFields: FilterFieldDef[] = [
  {
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { label: 'Draft', value: 'draft' },
      { label: 'Posted', value: 'posted' },
    ],
  },
  {
    key: 'warehouse_id',
    label: 'Warehouse',
    type: 'select',
    options: props.warehouses.map((w) => ({ label: w.name, value: String(w.id) })),
  },
]

const columns = [
  { key: 'reason', label: 'Reason' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'issue_date_formatted', label: 'Date', sortable: true, sortKey: 'issue_date' },
  { key: 'line_count', label: 'Lines', align: 'right' as const },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('inventory.goodsIssues.index'), {
    status: filters.value.status,
    warehouse_id: filters.value.warehouse_id,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: IssueRow | Record<string, unknown>) => {
  const row = item as IssueRow
  confirm({
    title: `Delete this draft issue?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('inventory.goodsIssues.destroy', row.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Goods Issues" description="Issue stock out — to a customer, a cost center, or unlinked consumption.">
      <template #actions>
        <PrimaryButton :href="route('inventory.goodsIssues.create')">New issue</PrimaryButton>
      </template>
    </PageHeader>

    <InventorySubNav active="goodsIssues" class="mt-6" />

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="issues.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="inventory.goodsIssues"
        :filter-fields="filterFields"
        export-filename="inventory-goods-issues"
        :total="issues.total"
        :from="issues.from"
        :to="issues.to"
        :links="issues.links"
        empty-title="No issues yet"
        empty-description="Create your first Goods Issue to deduct stock."
      >
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as IssueRow).status" />
        </template>
        <template #cell-reason="{ item }">
          <span class="text-ink-600">{{ (item as IssueRow).reason ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('inventory.goodsIssues.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              {{ (item as IssueRow).status === 'draft' ? 'Edit' : 'View' }}
            </Link>
            <button
              v-if="(item as IssueRow).status === 'draft'"
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
