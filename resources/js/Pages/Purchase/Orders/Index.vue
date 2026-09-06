<!-- Purchase Orders list (§3D) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'
import { useI18n } from '@/Composables/useI18n'

interface OrderItem {
  id: number
  uuid: string
  po_no: string
  supplier_name: string | null
  pr_no: string | null
  status: string
  revision_no: number
  currency_code: string
  total_amount: number
  expected_delivery_date: string | null
  ack_status: string | null
  lines_count: number
  created_at: string | null
}

const props = defineProps<{ orders: OrderItem[] }>()

const { t } = useI18n()
const search = ref('')
const sort = ref<SortState>(null)

const columns = computed(() => [
  { key: 'po_no', label: t('purchase.po_number'), sortable: true },
  { key: 'supplier_name', label: t('purchase.vendor'), sortable: true },
  { key: 'pr_no', label: t('purchase.linked_pr') },
  { key: 'expected_delivery_date', label: t('purchase.expected_delivery'), sortable: true },
  { key: 'total_amount', label: t('purchase.total_amount'), align: 'right' as const, sortable: true },
  { key: 'status', label: t('common.status'), sortable: true },
  { key: 'ack_status', label: t('purchase.acknowledgment') },
  { key: 'revision_no', label: t('purchase.revision'), align: 'center' as const },
  { key: 'actions', label: t('common.actions'), align: 'right' as const },
])

const formatCurrency = (val: number, curr = 'IDR') => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: curr, maximumFractionDigits: 0 }).format(val)
}

const filteredOrders = computed(() => {
  let list = props.orders
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((o) =>
      (o.po_no ?? '').toLowerCase().includes(q) ||
      (o.supplier_name ?? '').toLowerCase().includes(q) ||
      (o.pr_no ?? '').toLowerCase().includes(q)
    )
  }
  if (sort.value) {
    const { key, direction } = sort.value
    list = [...list].sort((a, b) => {
      const av = (a as unknown as Record<string, unknown>)[key] ?? ''
      const bv = (b as unknown as Record<string, unknown>)[key] ?? ''
      if (typeof av === 'number' && typeof bv === 'number') {
        return direction === 'asc' ? av - bv : bv - av
      }
      return direction === 'asc'
        ? String(av).localeCompare(String(bv))
        : String(bv).localeCompare(String(av))
    })
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="t('purchase.orders')" :description="t('purchase.orders_subtitle')">
      <template #actions>
        <PrimaryButton :href="route('purchase.orders.create')">{{ t('purchase.new_order') }}</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="orders" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredOrders"
        v-model:sort="sort"
        v-model:search="search"
        :search-placeholder="t('purchase.search_orders_placeholder')"
        status-rail-key="status"
        :empty-title="t('purchase.empty_orders_title')"
        :empty-description="t('purchase.empty_orders_desc')"
      >
        <template #cell-po_no="{ item }">
          <Link :href="route('purchase.orders.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.po_no }}
          </Link>
          <div class="text-xs text-ink-500">{{ item.lines_count }} item(s)</div>
        </template>
        <template #cell-supplier_name="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ item.supplier_name ?? '—' }}</div>
        </template>
        <template #cell-pr_no="{ item }">
          <div class="text-sm text-ink-700">{{ item.pr_no ?? '—' }}</div>
        </template>
        <template #cell-expected_delivery_date="{ item }">
          <div class="text-sm text-ink-700">{{ item.expected_delivery_date ?? '—' }}</div>
        </template>
        <template #cell-total_amount="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ formatCurrency(item.total_amount, item.currency_code) }}</div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-ack_status="{ item }">
          <span v-if="item.ack_status === 'accepted'" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
            Accepted
          </span>
          <span v-else-if="item.ack_status === 'accepted_with_changes'" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
            With changes
          </span>
          <span v-else-if="item.ack_status === 'rejected'" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-rose-100 text-rose-800">
            Rejected
          </span>
          <span v-else class="text-xs text-ink-400">
            —
          </span>
        </template>
        <template #cell-revision_no="{ item }">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-elevated text-ink-700 border border-border">
            v{{ item.revision_no }}
          </span>
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.orders.show', item.id)"
              class="text-sm font-medium text-ink-600 hover:text-ink-900"
            >
              View
            </Link>
            <Link
              v-if="item.status !== 'closed' && item.status !== 'cancelled'"
              :href="route('purchase.orders.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              {{ item.status === 'draft' ? 'Edit' : 'Amend' }}
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
