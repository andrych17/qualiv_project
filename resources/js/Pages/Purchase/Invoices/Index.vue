<!-- Purchase Invoices & Three-Way Match list (§3F) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface InvoiceItem {
  id: number
  uuid: string
  supplier_invoice_no: string
  supplier_invoice_date: string | null
  po_id: number
  po_no: string | null
  supplier_name: string | null
  currency_code: string
  amount: number
  match_status: string
  status: string
  lines_count: number
  ap_bill_id: number | null
}

const props = defineProps<{ invoices: InvoiceItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'supplier_invoice_no', label: 'Invoice No', sortable: true },
  { key: 'po_no', label: 'PO Number', sortable: true },
  { key: 'supplier_name', label: 'Supplier / Vendor', sortable: true },
  { key: 'supplier_invoice_date', label: 'Invoice Date', sortable: true },
  { key: 'amount', label: 'Amount', sortable: true, align: 'right' as const },
  { key: 'match_status', label: '3-Way Match' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const formatCurrency = (val: number, cur: string = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: cur || 'IDR', maximumFractionDigits: 0 }).format(val)
}

const filteredInvoices = computed(() => {
  let list = props.invoices
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((inv) =>
      (inv.supplier_invoice_no ?? '').toLowerCase().includes(q) ||
      (inv.po_no ?? '').toLowerCase().includes(q) ||
      (inv.supplier_name ?? '').toLowerCase().includes(q)
    )
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = (a as unknown as Record<string, unknown>)[key]
      const bv = (b as unknown as Record<string, unknown>)[key]
      if (typeof av === 'number' && typeof bv === 'number') {
        return direction === 'asc' ? av - bv : bv - av
      }
      const as = String(av ?? '')
      const bs = String(bv ?? '')
      return direction === 'asc' ? as.localeCompare(bs) : bs.localeCompare(as)
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Vendor Invoices & 3-Way Match" description="Capture supplier bills and validate PO, GR, and Invoice matching before AP booking (§3F).">
      <template #actions>
        <PrimaryButton :href="route('purchase.invoices.create')">Capture invoice</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="invoices" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredInvoices"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search invoice number, PO, or supplier…"
        status-rail-key="status"
        empty-title="No vendor invoices captured yet"
        empty-description="Capture supplier invoices to run automated three-way matching against purchase orders and receipts."
      >
        <template #cell-supplier_invoice_no="{ item }">
          <Link :href="route('purchase.invoices.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.supplier_invoice_no }}
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
        <template #cell-supplier_invoice_date="{ item }">
          <div class="text-sm text-ink-700">{{ item.supplier_invoice_date ?? '—' }}</div>
        </template>
        <template #cell-amount="{ item }">
          <div class="text-sm font-semibold text-ink-900">{{ formatCurrency(item.amount, item.currency_code) }}</div>
        </template>
        <template #cell-match_status="{ item }">
          <span
            v-if="item.match_status === 'matched'"
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800"
          >
            ✓ 3-Way Matched
          </span>
          <span
            v-else-if="item.match_status === 'mismatch'"
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800"
          >
            ✕ Mismatch
          </span>
          <span
            v-else
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800"
          >
            Pending
          </span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.invoices.show', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              View Match
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
