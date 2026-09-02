<!-- ponytail: MES Scheduling / live dispatch queue (MES_SPECS.md §3Q) — re-sequences only the
     live queue in front of an operator, not a planning engine (that's PP §3H). Pure read model
     + one write lever (Promote); no pagination needed, the whole live queue is small by nature. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { formatNumber } from '@/Utils/formatters'

interface QueueRow {
  order_id: number
  order_number: string
  product_sku: string | null
  product_name: string | null
  production_model: string
  qty: number
  priority: string
  due_date: string | null
  current_step_code: string | null
  current_step_name: string | null
  work_center_id: number | null
  work_center_code: string | null
  setup_minutes: number | null
  material_status: 'available' | 'shortage' | 'unknown'
  same_campaign_as_previous: boolean
}

const props = defineProps<{
  queue: QueueRow[]
  workCenterId: number | null
  workCenters: Array<{ value: number; label: string }>
  shiftInSession: boolean
}>()

const workCenterId = ref<number | null>(props.workCenterId)

watch(workCenterId, () => {
  router.get(route('mes.dispatchQueue.index'), { work_center_id: workCenterId.value }, { preserveState: true, replace: true })
})

const columns = [
  { key: 'order_number', label: 'Order' },
  { key: 'product_sku', label: 'Product' },
  { key: 'current_step_name', label: 'Current Step' },
  { key: 'work_center_code', label: 'Work Center' },
  { key: 'priority', label: 'Priority' },
  { key: 'due_date', label: 'Due' },
  { key: 'material_status', label: 'Material' },
  { key: 'setup_minutes', label: 'Setup (min)' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const materialClass = (status: string) =>
  status === 'available'
    ? 'bg-signal-success/10 text-signal-success border-signal-success/25'
    : status === 'shortage'
      ? 'bg-signal-danger/10 text-signal-danger border-signal-danger/25'
      : 'bg-surface-50 text-ink-600 border-border'

const promote = (row: QueueRow) => {
  router.post(route('mes.dispatchQueue.promote', row.order_id))
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Dispatch Queue" description="The live shop-floor queue for released orders, ordered by priority and due date (MES_SPECS.md §3Q). Re-sequences as floor conditions change — promote an order to urgent when material arrives or a machine comes back up. PP's own Production Plan / Detailed Scheduling (§3H) proposes the schedule; this only re-sequences what's already released." />

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Queue Length" :value="formatNumber(queue.length)" icon="ListOrdered" />
      <StatCard
        title="Shift Coverage"
        :value="shiftInSession ? 'In Session' : 'No Shift'"
        description="whether any HCM shift is currently active, tenant-wide"
        icon="Users"
      />
      <StatCard
        title="Same-Campaign Rows"
        :value="formatNumber(queue.filter((r) => r.same_campaign_as_previous).length)"
        description="consecutive orders sharing a product — grouping opportunity"
        icon="Repeat"
      />
    </div>

    <div class="mt-6 max-w-xs">
      <FormSelect v-model="workCenterId" name="work_center_id" label="Work Center (all if blank)" :options="workCenters" />
    </div>

    <div class="mt-4 space-y-4">
      <DataTable
        :columns="columns"
        :items="queue"
        row-key="order_id"
        sticky-header
        storage-key="mes.dispatchQueue"
        empty-title="Nothing in the queue"
        empty-description="No released, in-progress, or paused orders match this filter right now."
      >
        <template #cell-order_number="{ item }">
          <span class="font-mono text-xs font-medium text-ink-900">{{ (item as QueueRow).order_number }}</span>
          <span v-if="(item as QueueRow).same_campaign_as_previous" class="ml-1 text-xs text-accent" title="Same product as the row above — a campaign grouping opportunity">↳ campaign</span>
        </template>
        <template #cell-product_sku="{ item }">
          <span class="text-xs text-ink-700">{{ (item as QueueRow).product_sku }} — {{ (item as QueueRow).product_name }}</span>
        </template>
        <template #cell-current_step_name="{ item }">
          <span class="text-xs text-ink-700">{{ (item as QueueRow).current_step_name ?? 'Not started' }}</span>
        </template>
        <template #cell-priority="{ item }">
          <StatusBadge :status="(item as QueueRow).priority" />
        </template>
        <template #cell-due_date="{ item }">
          <span class="text-xs text-ink-700">{{ (item as QueueRow).due_date ?? '—' }}</span>
        </template>
        <template #cell-material_status="{ item }">
          <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="materialClass((item as QueueRow).material_status)">
            {{ (item as QueueRow).material_status }}
          </span>
        </template>
        <template #cell-setup_minutes="{ item }">
          <span class="text-xs text-ink-700 tabular-nums">{{ (item as QueueRow).setup_minutes ?? '—' }}</span>
        </template>
        <template #cell-actions="{ item }">
          <button
            v-if="(item as QueueRow).priority !== 'urgent'"
            type="button"
            class="text-sm font-medium text-accent hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="promote(item as QueueRow)"
          >
            Promote
          </button>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
