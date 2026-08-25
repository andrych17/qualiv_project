<!-- ponytail: Stock Card (§3H) — read-only report over stock_ledger. Running balance/value is
     computed server-side over the product's full history, so filtering by date range or
     movement type only narrows which rows are SHOWN, never what the running balance means. -->
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormAsyncSearchableSelect from '@/Components/forms/FormAsyncSearchableSelect.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'

interface LedgerRow {
  id: number
  movement_date_formatted: string
  movement_type: string
  warehouse_name: string | null
  location_code: string | null
  qty_in: number
  qty_out: number
  unit_cost: number
  running_qty: number
  running_value: number
  reference_label: string
  reference_url: string | null
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
  product: { id: number; sku: string; name: string; base_uom_code: string | null } | null
  rows: PaginatedData<LedgerRow> | null
  summary: { ledger_qty: number; cached_qty: number; drifted: boolean } | null
  filters: {
    product_id?: string
    warehouse_id?: string
    location_id?: string
    movement_type?: string
    date_from?: string
    date_to?: string
    per_page?: string
  }
  warehouses: Array<{ id: number; name: string }>
  locations: Array<{ id: number; warehouse_id: number; code: string }>
}>()

const productId = ref<number | null>(props.product?.id ?? null)
const warehouseId = ref<number | null>(props.filters.warehouse_id ? Number(props.filters.warehouse_id) : null)
const locationId = ref<number | null>(props.filters.location_id ? Number(props.filters.location_id) : null)
const movementType = ref(props.filters.movement_type ?? '')
const dateFrom = ref(props.filters.date_from ?? '')
const dateTo = ref(props.filters.date_to ?? '')
const perPage = ref(Number(props.filters.per_page) || props.rows?.per_page || 20)

const locationOptions = computed(() =>
  props.locations.filter((l) => Number(l.warehouse_id) === Number(warehouseId.value)).map((l) => ({ label: l.code, value: l.id })),
)

// Changing warehouse invalidates whatever location was picked under the old one.
watch(warehouseId, () => { locationId.value = null })

const applyFilters = () => {
  router.get(route('inventory.stockCard.index'), {
    product_id: productId.value,
    warehouse_id: warehouseId.value,
    location_id: locationId.value,
    movement_type: movementType.value,
    date_from: dateFrom.value,
    date_to: dateTo.value,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}

watch(perPage, applyFilters)

const columns = [
  { key: 'movement_date_formatted', label: 'Date' },
  { key: 'movement_type', label: 'Type' },
  { key: 'reference_label', label: 'Reference' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'location_code', label: 'Location' },
  { key: 'qty_in', label: 'Qty In', align: 'right' as const },
  { key: 'qty_out', label: 'Qty Out', align: 'right' as const },
  { key: 'unit_cost', label: 'Unit Cost', align: 'right' as const },
  { key: 'running_qty', label: 'Running Balance', align: 'right' as const },
  { key: 'running_value', label: 'Running Value', align: 'right' as const },
]
</script>

<template>
  <AppLayout>
    <PageHeader title="Stock Card" description="Chronological ledger per product — always reconstructable from stock_ledger alone." />

    <InventorySubNav active="stockCard" class="mt-6" />

    <Panel class="mt-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="sm:col-span-3 lg:col-span-2">
          <FormAsyncSearchableSelect
            v-model="productId"
            name="product_id"
            label="Product"
            api-entity="inventory_product"
            placeholder="Search SKU or name…"
          />
        </div>
        <FormSelect
          v-model="warehouseId"
          name="warehouse_id"
          label="Warehouse"
          placeholder="All warehouses"
          :options="warehouses.map((w) => ({ label: w.name, value: w.id }))"
        />
        <FormSelect
          v-model="locationId"
          name="location_id"
          label="Location"
          placeholder="All locations"
          :options="locationOptions"
          :disabled="!warehouseId"
        />
        <FormSelect
          v-model="movementType"
          name="movement_type"
          label="Movement type"
          placeholder="All types"
          :options="[
            { label: 'Receipt', value: 'receipt' },
            { label: 'Issue', value: 'issue' },
            { label: 'Transfer', value: 'transfer' },
            { label: 'Adjustment', value: 'adjustment' },
          ]"
        />
        <div class="grid grid-cols-2 gap-2 sm:col-span-2 lg:col-span-1">
          <FormInput v-model="dateFrom" name="date_from" type="date" label="From" />
          <FormInput v-model="dateTo" name="date_to" type="date" label="To" />
        </div>
      </div>

      <div class="mt-4 flex justify-end">
        <PrimaryButton type="button" :disabled="!productId" @click="applyFilters">View stock card</PrimaryButton>
      </div>
    </Panel>

    <div v-if="!product" class="mt-6 rounded-md border border-dashed border-border p-8 text-center text-sm text-ink-600">
      Search for a product above to view its stock card.
    </div>

    <template v-else>
      <Panel class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="font-serif text-lg font-semibold text-ink-900">{{ product.sku }} — {{ product.name }}</p>
            <p class="text-xs text-ink-600">Base UoM: {{ product.base_uom_code ?? '—' }}</p>
          </div>
          <div v-if="summary" class="flex items-center gap-6 text-sm">
            <div>
              <p class="text-xs uppercase tracking-wide text-ink-600">Ledger balance</p>
              <p class="font-mono font-semibold text-ink-900">{{ summary.ledger_qty }}</p>
            </div>
            <div>
              <p class="text-xs uppercase tracking-wide text-ink-600">Cached balance</p>
              <p class="font-mono font-semibold text-ink-900">{{ summary.cached_qty }}</p>
            </div>
          </div>
        </div>
        <p v-if="summary?.drifted" class="mt-3 rounded-sm border border-signal-warning/25 bg-signal-warning/10 px-3 py-2 text-sm text-signal-warning">
          The cached balance doesn't match the ledger — run <code class="font-mono text-xs">php artisan inventory:rebuild-stock-balances</code> to resync it. The ledger total above is always the source of truth.
        </p>
      </Panel>

      <div class="mt-6">
        <DataTable
          v-if="rows"
          :columns="columns"
          :items="rows.data"
          v-model:per-page="perPage"
          sticky-header
          storage-key="inventory.stockCard"
          export-filename="inventory-stock-card"
          :total="rows.total"
          :from="rows.from"
          :to="rows.to"
          :links="rows.links"
          empty-title="No movements yet"
          empty-description="This product has no stock_ledger entries for the selected filters."
        >
          <template #cell-movement_type="{ item }">
            <span class="text-xs uppercase text-ink-600">{{ (item as LedgerRow).movement_type }}</span>
          </template>
          <template #cell-reference_label="{ item }">
            <a
              v-if="(item as LedgerRow).reference_url"
              :href="(item as LedgerRow).reference_url!"
              class="text-sm font-medium text-accent hover:underline"
            >
              {{ (item as LedgerRow).reference_label }}
            </a>
            <span v-else class="text-ink-600">{{ (item as LedgerRow).reference_label }}</span>
          </template>
          <template #cell-qty_in="{ item }">
            <span class="font-mono text-xs text-signal-success">{{ (item as LedgerRow).qty_in > 0 ? (item as LedgerRow).qty_in : '' }}</span>
          </template>
          <template #cell-qty_out="{ item }">
            <span class="font-mono text-xs text-signal-danger">{{ (item as LedgerRow).qty_out > 0 ? (item as LedgerRow).qty_out : '' }}</span>
          </template>
          <template #cell-unit_cost="{ item }">
            <span class="font-mono text-xs text-ink-600">{{ (item as LedgerRow).unit_cost }}</span>
          </template>
          <template #cell-running_qty="{ item }">
            <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as LedgerRow).running_qty }}</span>
          </template>
          <template #cell-running_value="{ item }">
            <span class="font-mono text-xs font-semibold text-ink-900">{{ (item as LedgerRow).running_value }}</span>
          </template>
        </DataTable>
      </div>
    </template>
  </AppLayout>
</template>
