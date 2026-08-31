<!-- ponytail: Demand Forecasts listing (PP_SPECS.md §3B) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { ref, watch } from 'vue'
import { debounce } from '@/Composables/debounce'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ForecastRow {
  id: number
  product_sku: string | null
  product_name: string | null
  period_start: string
  qty: number
  source: string
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
  forecasts: PaginatedData<ForecastRow>
  filters: { search?: string; sort?: string; direction?: string; per_page?: string }
}>()

const search = ref(props.filters.search ?? '')
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.forecasts.per_page)

const columns = [
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'period_start', label: 'Period', sortable: true },
  { key: 'qty', label: 'Qty', align: 'right' as const, sortable: true },
  { key: 'source', label: 'Source' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

watch([search, sort, perPage], debounce(() => {
  selected.value = []
  router.get(route('pp.demandForecasts.index'), {
    search: search.value,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const { confirm } = useConfirm()

const confirmDelete = (item: ForecastRow | Record<string, unknown>) => {
  const row = item as ForecastRow
  confirm({
    title: `Delete forecast for ${row.product_sku}?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('pp.demandForecasts.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Delete ${selected.value.length} selected forecast(s)?`,
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () =>
      router.delete(route('pp.demandForecasts.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Demand Forecasts"
      description="Manually entered or imported forecast rows — each one nets into the baseline demand plan."
    >
      <template #actions>
        <PrimaryButton :href="route('pp.demandForecasts.create')">Add forecast</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 space-y-4">
      <DataTable
        :columns="columns"
        :items="forecasts.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.demandForecasts"
        search-placeholder="Search SKU or name…"
        export-filename="pp-demand-forecasts"
        :total="forecasts.total"
        :from="forecasts.from"
        :to="forecasts.to"
        :links="forecasts.links"
        empty-title="No forecasts yet"
        empty-description="Add a forecast row to project demand for a future period."
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
          <span class="font-mono text-xs text-ink-900">{{ (item as ForecastRow).product_sku }}</span>
        </template>
        <template #cell-qty="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ForecastRow).qty }}</span>
        </template>
        <template #cell-source="{ item }">
          <span class="text-xs uppercase text-ink-600">{{ (item as ForecastRow).source }}</span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('pp.demandForecasts.edit', item.id)"
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
