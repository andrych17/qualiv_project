<!-- Purchase Requisitions list (§3B) — PR Spine Entry Point -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface RequisitionItem {
  id: number
  uuid: string
  pr_no: string
  requester_name: string | null
  cost_center_name: string | null
  needed_by: string | null
  status: string
  estimated_total: number
  budget_warning: boolean
  duplicate_warning: boolean
  lines_count: number
  created_at: string | null
}

const props = defineProps<{ requisitions: RequisitionItem[] }>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'pr_no', label: 'PR Number', sortable: true },
  { key: 'requester_name', label: 'Requester', sortable: true },
  { key: 'cost_center_name', label: 'Cost Center' },
  { key: 'needed_by', label: 'Needed By', sortable: true },
  { key: 'estimated_total', label: 'Estimated Total', align: 'right' as const, sortable: true },
  { key: 'warnings', label: 'Warnings' },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const formatCurrency = (val: number) => {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(val)
}

const filteredRequisitions = computed(() => {
  let list = props.requisitions
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((r) =>
      (r.pr_no ?? '').toLowerCase().includes(q) ||
      (r.requester_name ?? '').toLowerCase().includes(q) ||
      (r.cost_center_name ?? '').toLowerCase().includes(q)
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
    <PageHeader title="Purchase Requisitions" description="Create and track internal procurement requests (§3B).">
      <template #actions>
        <PrimaryButton :href="route('purchase.requisitions.create')">New requisition</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="requisitions" />
    </div>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="filteredRequisitions"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search PR number, requester, or cost center…"
        status-rail-key="status"
        empty-title="No purchase requisitions yet"
        empty-description="Create your first purchase requisition to start the procurement process."
      >
        <template #cell-pr_no="{ item }">
          <Link :href="route('purchase.requisitions.show', item.id)" class="text-sm font-semibold text-accent hover:underline">
            {{ item.pr_no }}
          </Link>
          <div class="text-xs text-ink-500">{{ item.lines_count }} item(s)</div>
        </template>
        <template #cell-requester_name="{ item }">
          <div class="text-sm text-ink-900">{{ item.requester_name ?? '—' }}</div>
        </template>
        <template #cell-cost_center_name="{ item }">
          <div class="text-sm text-ink-700">{{ item.cost_center_name ?? '—' }}</div>
        </template>
        <template #cell-needed_by="{ item }">
          <div class="text-sm text-ink-700">{{ item.needed_by ?? '—' }}</div>
        </template>
        <template #cell-estimated_total="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ formatCurrency(item.estimated_total) }}</div>
        </template>
        <template #cell-warnings="{ item }">
          <div class="flex flex-wrap gap-1">
            <span v-if="item.budget_warning" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800" title="Budget exceeded warning">
              Budget
            </span>
            <span v-if="item.duplicate_warning" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-rose-100 text-rose-800" title="Possible duplicate PR warning">
              Duplicate
            </span>
            <span v-if="!item.budget_warning && !item.duplicate_warning" class="text-xs text-ink-400">
              —
            </span>
          </div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-2">
            <Link
              :href="route('purchase.requisitions.show', item.id)"
              class="text-sm font-medium text-ink-600 hover:text-ink-900"
            >
              View
            </Link>
            <Link
              v-if="item.status === 'draft' || item.status === 'rejected'"
              :href="route('purchase.requisitions.edit', item.id)"
              class="text-sm font-medium text-accent hover:underline"
            >
              Edit
            </Link>
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
