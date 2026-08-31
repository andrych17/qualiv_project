<!-- ponytail: Demand Aggregation listing (PP_SPECS.md §3B) — read model over every source -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface DemandRow {
  id: number
  demand_hdr_id: number
  product_sku: string | null
  product_name: string | null
  source_type: string | null
  note: string | null
  need_by_date: string
  qty: number
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
  lines: PaginatedData<DemandRow>
  filters: { search?: string; source_type?: string; sort?: string; direction?: string; per_page?: string }
  sourceTypes: string[]
}>()

const search = ref(props.filters.search ?? '')
const filters = ref({ source_type: props.filters.source_type ?? '' })
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.lines.per_page)

const sourceLabel = (type: string) => ({
  manual: 'Manual',
  forecast: 'Forecast',
  sales_order: 'Sales Order',
  safety_stock: 'Safety Stock',
  blanket_order: 'Blanket Order',
  dependent: 'Dependent',
  transfer: 'Transfer',
}[type] ?? type)

const filterFields: FilterFieldDef[] = [
  {
    key: 'source_type',
    label: 'Source',
    type: 'select',
    options: props.sourceTypes.map((t) => ({ label: sourceLabel(t), value: t })),
  },
]

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'source_type', label: 'Source' },
  { key: 'need_by_date', label: 'Need by', sortable: true },
  { key: 'qty', label: 'Qty', align: 'right' as const, sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, filters, sort, perPage], debounce(() => {
  router.get(route('pp.demand.index'), {
    search: search.value,
    source_type: filters.value.source_type,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: DemandRow | Record<string, unknown>) => {
  const row = item as DemandRow
  confirm({
    title: `Delete manual demand for ${row.product_sku}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.demand.destroy', row.demand_hdr_id)),
  })
}

const recalculateSafetyStock = () => router.post(route('pp.demand.recalculateSafetyStock'))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Demand Aggregation"
      description="Every source of production demand — Sales orders, forecasts, safety stock shortfalls and manual plans — netted into one baseline plan."
    >
      <template #actions>
        <SecondaryButton @click="recalculateSafetyStock">Recalculate safety stock</SecondaryButton>
        <PrimaryButton :href="route('pp.demand.create')">Add manual demand</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="lines.data"
        v-model:sort="sort"
        v-model:search="search"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="pp.demand"
        search-placeholder="Search SKU or name…"
        :filter-fields="filterFields"
        export-filename="pp-demand"
        :total="lines.total"
        :from="lines.from"
        :to="lines.to"
        :links="lines.links"
        empty-title="No demand yet"
        empty-description="Confirm a Sales order, add a forecast, or enter a manual plan to see demand here."
      >
        <template #cell-product_sku="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as DemandRow).product_sku }}</span>
        </template>
        <template #cell-source_type="{ item }">
          <StatusBadge
            :status="(item as DemandRow).source_type === 'manual' ? 'draft' : 'active'"
            :label="sourceLabel((item as DemandRow).source_type ?? '')"
          />
        </template>
        <template #cell-qty="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as DemandRow).qty }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <template v-if="(item as DemandRow).source_type === 'manual'">
              <Link
                :href="route('pp.demand.edit', (item as DemandRow).demand_hdr_id)"
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
            </template>
            <span v-else class="text-xs text-ink-600">System-generated</span>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
