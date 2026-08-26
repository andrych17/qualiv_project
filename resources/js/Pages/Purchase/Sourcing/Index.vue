<!-- Purchase Sourcing / RFQ list (§3C) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface RfxItem {
  id: number
  uuid: string
  rfx_no: string
  type: string
  pr_no: string | null
  due_date: string
  status: string
  lines_count: number
  suppliers_count: number
  responses_count: number
  creator_name: string | null
  created_at: string | null
}

const props = defineProps<{ rfxList: RfxItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'rfx_no', label: 'RFQ Number', sortable: true },
  { key: 'pr_no', label: 'Linked PR', sortable: true },
  { key: 'lines_count', label: 'Items', align: 'right' as const },
  { key: 'quotes_progress', label: 'Quotes Received' },
  { key: 'due_date', label: 'Due Date', sortable: true },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const filteredRfx = computed(() => {
  let list = props.rfxList
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((r) =>
      (r.rfx_no ?? '').toLowerCase().includes(q) ||
      (r.pr_no ?? '').toLowerCase().includes(q)
    )
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Sourcing & Quotations (RFQ)" description="Competitive bidding, supplier quotation intake, and side-by-side comparison matrix (§3C).">
      <template #actions>
        <PrimaryButton :href="route('purchase.sourcing.create')">New RFQ / Sourcing</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="sourcing" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredRfx"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search RFQ number or PR…"
        empty-title="No RFQs created yet"
        empty-description="Create RFQs to invite vendor quotes and compare pricing side-by-side."
      >
        <template #cell-rfx_no="{ item }">
          <Link :href="route('purchase.sourcing.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.rfx_no }}
          </Link>
          <div class="text-xs text-ink-500">{{ item.type }}</div>
        </template>
        <template #cell-pr_no="{ item }">
          <span v-if="item.pr_no" class="text-sm text-ink-700 font-medium">{{ item.pr_no }}</span>
          <span v-else class="text-xs text-ink-400">Standalone</span>
        </template>
        <template #cell-lines_count="{ item }">
          <span class="text-sm font-medium text-ink-900">{{ item.lines_count }} item(s)</span>
        </template>
        <template #cell-quotes_progress="{ item }">
          <div class="flex items-center gap-2">
            <span
              class="text-xs font-semibold px-2 py-0.5 rounded"
              :class="item.responses_count >= item.suppliers_count && item.suppliers_count > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-surface-elevated text-ink-700'"
            >
              {{ item.responses_count }} / {{ item.suppliers_count }} vendors
            </span>
          </div>
        </template>
        <template #cell-due_date="{ item }">
          <div class="text-xs text-ink-700">{{ item.due_date }}</div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <Link
            :href="route('purchase.sourcing.show', item.id)"
            class="text-sm font-medium text-accent hover:underline"
          >
            View Comparison →
          </Link>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
