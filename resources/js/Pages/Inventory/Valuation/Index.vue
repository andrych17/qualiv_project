<!-- ponytail: Inventory Valuation (§3I) — read-only report. Backend returns one flat row per
     product×warehouse; grouping by Category/Warehouse is DataTable's client-side groupBy (same
     pattern as Projects' issue board), so there's no server-side GROUP BY per dimension to
     maintain. "As of" a past date switches the backend from live valuation-layer sums to a
     ledger replay — same rows shape either way, so the table doesn't need to know which mode
     produced them. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'

interface ValuationRow {
  product_id: number
  sku: string
  product_name: string
  category_id: number | null
  category_name: string
  warehouse_id: number
  warehouse_name: string | null
  qty: number
  unit_cost: number
  total_value: number
}

const props = defineProps<{
  rows: ValuationRow[]
  summary: { total_value: number; row_count: number; as_of_date: string | null }
  filters: { search?: string; category_id?: string; warehouse_id?: string; as_of_date?: string }
  categories: Array<{ id: number; name: string }>
  warehouses: Array<{ id: number; name: string }>
}>()

const search = ref(props.filters.search ?? '')
const categoryId = ref<number | null>(props.filters.category_id ? Number(props.filters.category_id) : null)
const warehouseId = ref<number | null>(props.filters.warehouse_id ? Number(props.filters.warehouse_id) : null)
const asOfDate = ref(props.filters.as_of_date ?? '')
const groupByField = ref<'' | 'category_name' | 'warehouse_name'>('')

const applyFilters = () => {
  router.get(route('inventory.valuation.index'), {
    search: search.value,
    category_id: categoryId.value,
    warehouse_id: warehouseId.value,
    as_of_date: asOfDate.value,
  }, { preserveState: true, replace: true })
}

const groupBy = computed(() => groupByField.value || undefined)

const columns = [
  { key: 'sku', label: 'SKU', sortable: true },
  { key: 'product_name', label: 'Product', sortable: true },
  { key: 'category_name', label: 'Category', sortable: true },
  { key: 'warehouse_name', label: 'Warehouse', sortable: true },
  { key: 'qty', label: 'Qty on hand', align: 'right' as const, footer: 'sum' as const },
  { key: 'unit_cost', label: 'Unit Cost', align: 'right' as const },
  { key: 'total_value', label: 'Total Value', align: 'right' as const, footer: 'sum' as const },
]
</script>

<template>
  <AppLayout>
    <PageHeader title="Inventory Valuation" description="On-hand value by product, category, and warehouse — always derived from the ledger, never a mutable field." />

    <InventorySubNav active="valuation" class="mt-6" />

    <Panel class="mt-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <FormInput v-model="search" name="search" label="Search" placeholder="SKU or name…" @keyup.enter="applyFilters" />
        <FormSelect
          v-model="categoryId"
          name="category_id"
          label="Category"
          placeholder="All categories"
          :options="categories.map((c) => ({ label: c.name, value: c.id }))"
        />
        <FormSelect
          v-model="warehouseId"
          name="warehouse_id"
          label="Warehouse"
          placeholder="All warehouses"
          :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
        />
        <FormInput v-model="asOfDate" name="as_of_date" type="date" label="As of date" />
        <FormSelect
          v-model="groupByField"
          name="group_by"
          label="Group by"
          :options="[
            { label: 'None', value: '' },
            { label: 'Category', value: 'category_name' },
            { label: 'Warehouse', value: 'warehouse_name' },
          ]"
        />
      </div>

      <div class="mt-4 flex justify-end">
        <PrimaryButton type="button" @click="applyFilters">Apply filters</PrimaryButton>
      </div>
    </Panel>

    <Panel class="mt-6">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-xs uppercase tracking-wide text-ink-600">
            {{ summary.as_of_date ? `Value as of ${summary.as_of_date}` : 'Current value' }}
          </p>
          <p class="font-mono text-2xl font-semibold text-ink-900">{{ summary.total_value }}</p>
        </div>
        <p class="text-sm text-ink-600">{{ summary.row_count }} product/warehouse row(s)</p>
      </div>
      <p v-if="summary.as_of_date" class="mt-3 text-xs text-ink-600">
        Replayed from stock_ledger up to this date — no separate period-close process is needed since the ledger is always the source of truth.
      </p>
    </Panel>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="rows"
        :group-by="groupBy"
        sticky-header
        storage-key="inventory.valuation"
        export-filename="inventory-valuation"
        empty-title="Nothing to value"
        empty-description="No open stock for the selected filters."
      >
        <template #cell-qty="{ item }">
          <span class="font-mono text-xs text-ink-900">{{ (item as ValuationRow).qty }}</span>
        </template>
        <template #cell-unit_cost="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ValuationRow).unit_cost }}</span>
        </template>
        <template #cell-total_value="{ item }">
          <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as ValuationRow).total_value }}</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
