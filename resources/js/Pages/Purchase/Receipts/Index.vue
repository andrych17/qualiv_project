<!-- Purchase Goods Receipts list (§3E) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface ReceiptItem {
  id: number
  uuid: string
  gr_no: string
  po_id: number
  po_no: string | null
  supplier_name: string | null
  receiver_name: string | null
  received_at: string | null
  status: string
  lines_count: number
}

const props = defineProps<{ receipts: ReceiptItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'gr_no', label: 'GR Number', sortable: true },
  { key: 'po_no', label: 'PO Number', sortable: true },
  { key: 'supplier_name', label: 'Supplier / Vendor', sortable: true },
  { key: 'receiver_name', label: 'Receiver' },
  { key: 'received_at', label: 'Received At', sortable: true },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredReceipts = computed(() => {
  let list = props.receipts
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((r) =>
      (r.gr_no ?? '').toLowerCase().includes(q) ||
      (r.po_no ?? '').toLowerCase().includes(q) ||
      (r.supplier_name ?? '').toLowerCase().includes(q) ||
      (r.receiver_name ?? '').toLowerCase().includes(q)
    )
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = String((a as unknown as Record<string, unknown>)[key] ?? '')
      const bv = String((b as unknown as Record<string, unknown>)[key] ?? '')
      return direction === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av)
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Goods Receipts" description="Authoritative receiving records for three-way match and stock fulfillment (§3E).">
      <template #actions>
        <PrimaryButton :href="route('purchase.receipts.create')">New goods receipt</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="receipts" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredReceipts"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search GR number, PO, or supplier…"
        status-rail-key="status"
        empty-title="No goods receipts yet"
        empty-description="Record physical goods received against active purchase orders."
      >
        <template #cell-gr_no="{ item }">
          <Link :href="route('purchase.receipts.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.gr_no }}
          </Link>
          <div class="text-xs text-ink-500">{{ item.lines_count }} line(s)</div>
        </template>
        <template #cell-po_no="{ item }">
          <Link v-if="item.po_no" :href="route('purchase.orders.show', item.po_id)" class="text-sm font-medium text-ink-900 hover:underline">
            {{ item.po_no }}
          </Link>
          <span v-else class="text-ink-400">—</span>
        </template>
        <template #cell-supplier_name="{ item }">
          <div class="text-sm text-ink-900">{{ item.supplier_name ?? '—' }}</div>
        </template>
        <template #cell-receiver_name="{ item }">
          <div class="text-sm text-ink-700">{{ item.receiver_name ?? '—' }}</div>
        </template>
        <template #cell-received_at="{ item }">
          <div class="text-sm text-ink-700">{{ item.received_at ?? '—' }}</div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.receipts.show', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              View
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
