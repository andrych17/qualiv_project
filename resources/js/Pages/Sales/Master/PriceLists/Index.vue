<!-- Price Lists Index (§3B) -->
<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import SalesSubNav from '@/Components/sales/SalesSubNav.vue'
import SalesMasterSubNav from '@/Components/sales/SalesMasterSubNav.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface PriceListItem {
  id: number
  name: string
  currency: string
  customer_segment: string | null
  is_tenant_default: boolean
  is_active: boolean
  territory?: { name: string }
  lines_count?: number
}

const props = defineProps<{
  priceLists: PriceListItem[]
}>()

const search = ref('')
const sort = ref<SortState>(null)
const selected = ref<Array<string | number>>([])

const columns = [
  { key: 'name', label: 'Price List Name', sortable: true },
  { key: 'currency', label: 'Currency' },
  { key: 'territory', label: 'Territory' },
  { key: 'customer_segment', label: 'Customer Segment' },
  { key: 'is_tenant_default', label: 'Default', align: 'center' as const },
  { key: 'is_active', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const { confirm } = useConfirm()

const deletePriceList = (pl: PriceListItem) => {
  confirm({
    title: `Delete Price List "${pl.name}"?`,
    description: 'Are you sure you want to delete this price list and all its pricing tiers?',
    variant: 'destructive',
    confirmText: 'Delete',
    onConfirm: () => router.delete(route('sales.master.price-lists.destroy', pl.id)),
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Sales Master Configuration"
      description="Manage price lists, pricing matrices, sales territories, rep teams, and promo codes."
    >
      <template #actions>
        <PrimaryButton :href="route('sales.master.price-lists.create')">New Price List</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <SalesSubNav active="master" />
    </div>

    <div class="mt-4">
      <SalesMasterSubNav active="price-lists" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="props.priceLists"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:search="search"
        sticky-header
        storage-key="sales.master.price-lists"
        search-placeholder="Search price lists…"
        export-filename="sales-price-lists"
        status-rail-key="is_active"
        empty-title="No price lists found"
        empty-description="Create your first price list matrix with item discounts."
      >
        <template #cell-name="{ item }">
          <Link
            :href="route('sales.master.price-lists.edit', item.id)"
            class="font-semibold text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
          >
            {{ (item as PriceListItem).name }}
          </Link>
        </template>

        <template #cell-currency="{ item }">
          <span class="font-mono text-xs text-ink-700">{{ (item as PriceListItem).currency }}</span>
        </template>

        <template #cell-territory="{ item }">
          <span class="text-ink-600">{{ (item as PriceListItem).territory?.name ?? 'All Territories' }}</span>
        </template>

        <template #cell-customer_segment="{ item }">
          <span class="text-ink-600 capitalize">{{ (item as PriceListItem).customer_segment ?? 'General' }}</span>
        </template>

        <template #cell-is_tenant_default="{ item }">
          <span v-if="(item as PriceListItem).is_tenant_default" class="text-xs font-semibold text-accent bg-accent/10 px-2 py-0.5 rounded">
            Tenant Default
          </span>
          <span v-else class="text-ink-400 text-xs">-</span>
        </template>

        <template #cell-is_active="{ item }">
          <StatusBadge :status="(item as PriceListItem).is_active ? 'active' : 'inactive'" />
        </template>

        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              :href="route('sales.master.price-lists.edit', item.id)"
              class="text-xs font-semibold text-accent hover:underline"
            >
              Edit
            </Link>
            <button
              type="button"
              @click="deletePriceList(item as PriceListItem)"
              class="text-xs font-medium text-signal-danger hover:underline"
            >
              Delete
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
