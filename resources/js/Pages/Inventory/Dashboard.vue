<!-- ponytail: Inventory Main Dashboard (§3A) — read-only aggregate; every stat/row links back
     into the engine that owns it. Status Rail semantics per spec: danger = out of stock /
     negative variance, warning = below reorder point / expiring soon / awaiting review,
     success = healthy, info = system-generated movement, neutral = plain in-flight document. -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InventorySubNav from '@/Components/inventory/InventorySubNav.vue'
import { formatCurrency, formatNumber } from '@/Utils/formatters'
import { AlertTriangle } from 'lucide-vue-next'

interface NeedsAttentionItem {
  type: string
  label: string
  detail: string
  rail: 'danger' | 'warning'
  href: string
}

const props = defineProps<{
  metrics: {
    total_skus: number
    on_hand_value: number
    low_stock_count: number
    out_of_stock_count: number
    pending_receipts_count: number
    pending_shipments_count: number
    open_cycle_counts_count: number
  }
  needsAttention: NeedsAttentionItem[]
  lowStock: Array<{ product_id: number; sku: string; product_name: string; qty: number; reorder_point: number }>
  recentMovements: Array<{ id: number; product_sku: string | null; product_name: string | null; movement_type: string; qty: number; warehouse_name: string | null; movement_date_formatted: string | null; rail: string }>
  pendingDocuments: Array<{ type: string; reference: string; warehouse_name: string | null; date_formatted: string | null; status: string; href: string; rail: string }>
  openCounts: Array<{ id: number; warehouse_name: string | null; scope: string; assigned_to_name: string | null; progress: string; status: string; href: string; rail: string }>
}>()

const tab = ref<'lowStock' | 'recentMovements' | 'pendingDocuments' | 'openCounts'>('lowStock')

const tabs = [
  { key: 'lowStock', label: 'Low Stock', count: props.lowStock.length },
  { key: 'recentMovements', label: 'Recent Movements', count: props.recentMovements.length },
  { key: 'pendingDocuments', label: 'Pending Documents', count: props.pendingDocuments.length },
  { key: 'openCounts', label: 'Open Counts', count: props.openCounts.length },
] as const

const lowStockRows = computed(() => props.lowStock.map((r) => ({
  ...r,
  rail: r.qty <= 0.00005 ? 'danger' : 'warning',
})))

const lowStockColumns = [
  { key: 'sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'qty', label: 'On hand', align: 'right' as const },
  { key: 'reorder_point', label: 'Reorder point', align: 'right' as const },
]
const recentMovementsColumns = [
  { key: 'movement_date_formatted', label: 'Date' },
  { key: 'product_sku', label: 'SKU' },
  { key: 'product_name', label: 'Product' },
  { key: 'movement_type', label: 'Type' },
  { key: 'qty', label: 'Qty', align: 'right' as const },
  { key: 'warehouse_name', label: 'Warehouse' },
]
const pendingDocumentsColumns = [
  { key: 'type', label: 'Type' },
  { key: 'reference', label: 'Reference' },
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'date_formatted', label: 'Date' },
  { key: 'status', label: 'Status' },
]
const openCountsColumns = [
  { key: 'warehouse_name', label: 'Warehouse' },
  { key: 'scope', label: 'Scope' },
  { key: 'assigned_to_name', label: 'Assigned to' },
  { key: 'progress', label: 'Progress' },
  { key: 'status', label: 'Status' },
]
</script>

<template>
  <AppLayout>
    <PageHeader title="Inventory Dashboard" description="At-a-glance stock health across every warehouse (§3A).">
      <template #actions>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <SecondaryButton :href="route('inventory.transfers.create')">New Transfer</SecondaryButton>
          <SecondaryButton :href="route('inventory.goodsIssues.create')">New Goods Issue</SecondaryButton>
          <SecondaryButton :href="route('inventory.cycleCounts.create')">Scan-to-count</SecondaryButton>
          <PrimaryButton :href="route('inventory.goodsReceipts.create')">New Goods Receipt</PrimaryButton>
        </div>
      </template>
    </PageHeader>

    <InventorySubNav active="dashboard" class="mt-6" />

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
      <StatCard title="Total SKUs" :value="formatNumber(metrics.total_skus)" icon="Package" :href="route('inventory.products.index')" />
      <StatCard title="On-Hand Value" :value="formatCurrency(metrics.on_hand_value)" icon="Wallet" :href="route('inventory.valuation.index')" />
      <StatCard title="Low Stock" :value="formatNumber(metrics.low_stock_count)" icon="TrendingDown" />
      <StatCard title="Out of Stock" :value="formatNumber(metrics.out_of_stock_count)" icon="CircleAlert" />
      <StatCard title="Pending Receipts" :value="formatNumber(metrics.pending_receipts_count)" icon="PackageOpen" :href="route('inventory.goodsReceipts.index')" />
      <StatCard title="Pending Shipments" :value="formatNumber(metrics.pending_shipments_count)" icon="Truck" :href="route('inventory.shipments.index')" />
      <StatCard title="Open Cycle Counts" :value="formatNumber(metrics.open_cycle_counts_count)" icon="ClipboardList" :href="route('inventory.cycleCounts.index')" />
    </div>

    <Panel v-if="needsAttention.length > 0" title="Needs Attention" class="mt-6">
      <div class="divide-y divide-border">
        <Link
          v-for="(item, index) in needsAttention"
          :key="index"
          :href="item.href"
          class="flex items-center gap-3 py-3 transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
          <span class="h-8 w-1 shrink-0 rounded-full" :class="item.rail === 'danger' ? 'bg-signal-danger' : 'bg-signal-warning'" />
          <AlertTriangle class="h-4 w-4 shrink-0" :class="item.rail === 'danger' ? 'text-signal-danger' : 'text-signal-warning'" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink-900">{{ item.label }}</p>
            <p class="text-xs text-ink-600">{{ item.detail }}</p>
          </div>
          <span class="shrink-0 rounded-full bg-surface-50 px-2 py-0.5 text-[11px] font-medium text-ink-600 ring-1 ring-border">{{ item.type }}</span>
        </Link>
      </div>
    </Panel>

    <div class="mt-6 space-y-4">
      <Tabs v-model="tab" :tabs="[...tabs]" />

      <DataTable
        v-if="tab === 'lowStock'"
        :columns="lowStockColumns"
        :items="lowStockRows"
        status-rail-key="rail"
        row-key="product_id"
        empty-title="No low-stock products"
        empty-description="Every product with a reorder point is currently above it."
      >
        <template #cell-qty="{ item }">{{ formatNumber((item as any).qty) }}</template>
        <template #cell-reorder_point="{ item }">{{ formatNumber((item as any).reorder_point) }}</template>
      </DataTable>

      <DataTable
        v-else-if="tab === 'recentMovements'"
        :columns="recentMovementsColumns"
        :items="recentMovements"
        status-rail-key="rail"
        empty-title="No stock movements yet"
      >
        <template #cell-movement_type="{ item }"><StatusBadge :status="(item as any).movement_type" /></template>
        <template #cell-qty="{ item }">{{ formatNumber((item as any).qty) }}</template>
      </DataTable>

      <DataTable
        v-else-if="tab === 'pendingDocuments'"
        :columns="pendingDocumentsColumns"
        :items="pendingDocuments"
        status-rail-key="rail"
        row-key="reference"
        empty-title="No documents pending"
        empty-description="Every Goods Receipt, Goods Issue, Transfer, and Shipment is settled."
      >
        <template #cell-reference="{ item }">
          <Link :href="(item as any).href" class="font-medium text-accent hover:underline">{{ (item as any).reference }}</Link>
        </template>
        <template #cell-status="{ item }"><StatusBadge :status="(item as any).status" /></template>
      </DataTable>

      <DataTable
        v-else
        :columns="openCountsColumns"
        :items="openCounts"
        status-rail-key="rail"
        empty-title="No open cycle counts"
        empty-description="Start one from the Cycle Counts page."
      >
        <template #cell-warehouse_name="{ item }">
          <Link :href="(item as any).href" class="font-medium text-accent hover:underline">{{ (item as any).warehouse_name }}</Link>
        </template>
        <template #cell-status="{ item }"><StatusBadge :status="(item as any).status" /></template>
      </DataTable>
    </div>
  </AppLayout>
</template>
