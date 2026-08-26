<!-- Purchase Contract Management list (§3H) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface ContractItem {
  id: number
  uuid: string
  title: string
  type: string
  supplier_name: string | null
  value: number | null
  spend_amount: number
  currency_code: string
  start_date: string
  end_date: string
  status: string
  auto_renew: boolean
}

const props = defineProps<{ contracts: ContractItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'title', label: 'Contract Title', sortable: true },
  { key: 'supplier_name', label: 'Supplier', sortable: true },
  { key: 'type', label: 'Type' },
  { key: 'value', label: 'Contract Ceiling', sortable: true, align: 'right' as const },
  { key: 'spend_amount', label: 'Committed Spend', sortable: true, align: 'right' as const },
  { key: 'validity', label: 'Period' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const formatCurrency = (val: number | null, cur: string = 'IDR') => {
  if (val === null) return '—'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: cur || 'IDR', maximumFractionDigits: 0 }).format(val)
}

const filteredContracts = computed(() => {
  let list = props.contracts
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((c) =>
      (c.title ?? '').toLowerCase().includes(q) ||
      (c.supplier_name ?? '').toLowerCase().includes(q) ||
      (c.type ?? '').toLowerCase().includes(q)
    )
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Procurement Contracts" description="Supplier framework agreements, blanket orders, and spend tracking (§3H).">
      <template #actions>
        <PrimaryButton :href="route('purchase.contracts.create')">New contract</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="contracts" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredContracts"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search contracts or supplier…"
        empty-title="No contracts registered"
        empty-description="Create procurement contracts to track commitments, ceilings, and renewal deadlines."
      >
        <template #cell-title="{ item }">
          <Link :href="route('purchase.contracts.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.title }}
          </Link>
          <div v-if="item.auto_renew" class="text-xs text-ink-500">🔄 Auto-renew enabled</div>
        </template>
        <template #cell-supplier_name="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ item.supplier_name ?? '—' }}</div>
        </template>
        <template #cell-type="{ item }">
          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium uppercase tracking-wide bg-surface-elevated text-ink-700">
            {{ item.type }}
          </span>
        </template>
        <template #cell-value="{ item }">
          <div class="text-sm font-semibold text-ink-900">{{ formatCurrency(item.value, item.currency_code) }}</div>
        </template>
        <template #cell-spend_amount="{ item }">
          <div class="text-sm font-semibold" :class="item.value && item.spend_amount > item.value ? 'text-rose-700' : 'text-emerald-700'">
            {{ formatCurrency(item.spend_amount, item.currency_code) }}
          </div>
        </template>
        <template #cell-validity="{ item }">
          <div class="text-xs text-ink-700">{{ item.start_date }} → {{ item.end_date }}</div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.contracts.show', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              View
            </Link>
            <span class="text-ink-300">|</span>
            <Link
              :href="route('purchase.contracts.edit', item.id)"
              class="text-sm font-medium text-ink-600 hover:text-ink-900"
            >
              Edit
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
