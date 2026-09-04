<!-- Purchase Exception Management list (§3K) -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PurchaseSubNav from '@/Components/purchase/PurchaseSubNav.vue'

interface ExceptionItem {
  id: number
  exception_type: string
  subject_type: string
  subject_id: number
  summary: string
  status: string
  resolved_by: string | null
  resolved_at: string | null
  created_at: string | null
}

const props = defineProps<{
  exceptions: ExceptionItem[]
  currentStatus: string
  currentType: string | null
}>()

const search = ref('')
const sort = ref<SortState>(null)

const columns = [
  { key: 'exception_type', label: 'Type', sortable: true },
  { key: 'summary', label: 'Summary / Details', sortable: true },
  { key: 'status', label: 'Status' },
  { key: 'created_at', label: 'Detected At', sortable: true },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const formatType = (type: string) => {
  return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

const resolveException = (id: number) => {
  router.post(route('purchase.exceptions.resolve', id))
}

const dismissException = (id: number) => {
  router.post(route('purchase.exceptions.dismiss', id))
}

const scanExceptions = () => {
  router.post(route('purchase.exceptions.scan'))
}

const filterStatus = (status: string) => {
  router.get(route('purchase.exceptions.index', { status, type: props.currentType }))
}

const filteredExceptions = computed(() => {
  let list = props.exceptions
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter((e) =>
      (e.summary ?? '').toLowerCase().includes(q) ||
      (e.exception_type ?? '').toLowerCase().includes(q)
    )
  }
  return list
})
</script>

<template>
  <AppLayout>
    <PageHeader title="Procurement Exceptions" description="Automated anomaly and deviation logs across the procurement pipeline (§3K).">
      <template #actions>
        <SecondaryButton @click="scanExceptions">
          ⚡ Scan exceptions now
        </SecondaryButton>
      </template>
    </PageHeader>

    <div class="mt-4">
      <PurchaseSubNav active="exceptions" />
    </div>

    <!-- Status Filters -->
    <div class="mt-6 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-md transition"
          :class="currentStatus === 'open' ? 'bg-accent text-accent-text font-semibold' : 'bg-surface-0 border border-border text-ink-600 hover:bg-surface-50'"
          @click="filterStatus('open')"
        >
          Open Exceptions
        </button>
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-md transition"
          :class="currentStatus === 'resolved' ? 'bg-accent text-accent-text font-semibold' : 'bg-surface-0 border border-border text-ink-600 hover:bg-surface-50'"
          @click="filterStatus('resolved')"
        >
          Resolved
        </button>
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-md transition"
          :class="currentStatus === 'all' ? 'bg-accent text-accent-text font-semibold' : 'bg-surface-0 border border-border text-ink-600 hover:bg-surface-50'"
          @click="filterStatus('all')"
        >
          All Exceptions
        </button>
      </div>
    </div>

    <div class="mt-4">
      <DataTable
        :columns="columns"
        :items="filteredExceptions"
        v-model:sort="sort"
        v-model:search="search"
        search-placeholder="Search exceptions…"
        empty-title="No exceptions recorded"
        empty-description="Any deviations such as late deliveries, price variances, or unmatched invoices will appear here."
      >
        <template #cell-exception_type="{ item }">
          <span
            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
            :class="{
              'bg-rose-100 text-rose-800': item.exception_type === 'unmatched_invoice' || item.exception_type === 'price_variance',
              'bg-amber-100 text-amber-800': item.exception_type === 'late_delivery' || item.exception_type === 'overdue_approval',
              'bg-blue-100 text-blue-800': item.exception_type === 'budget_flag',
            }"
          >
            {{ formatType(item.exception_type) }}
          </span>
        </template>
        <template #cell-summary="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ item.summary }}</div>
          <div v-if="item.resolved_by" class="text-xs text-ink-500 mt-0.5">
            Resolved by {{ item.resolved_by }} on {{ item.resolved_at }}
          </div>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="item.status" />
        </template>
        <template #cell-created_at="{ item }">
          <div class="text-xs text-ink-700">{{ item.created_at }}</div>
        </template>
        <template #cell-actions="{ item }">
          <div v-if="item.status === 'open'" class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="text-xs font-semibold text-emerald-700 hover:text-emerald-800"
              @click="resolveException(item.id)"
            >
              Resolve
            </button>
            <span class="text-ink-300">|</span>
            <button
              type="button"
              class="text-xs font-medium text-ink-500 hover:text-ink-700"
              @click="dismissException(item.id)"
            >
              Dismiss
            </button>
          </div>
          <div v-else class="text-xs text-ink-400 text-right">
            Done
          </div>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
