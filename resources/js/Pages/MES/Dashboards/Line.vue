<!-- ponytail: Line Dashboard (MES_SPECS.md §3T) — one row per discrete Work Center (a "line"),
     composed from DataTable + StatusBadge only. Read model, nothing stored here. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import { debounce } from '@/Composables/debounce'
import { formatNumber } from '@/Utils/formatters'

interface LineRow {
  work_center_id: number
  code: string
  name: string
  area_line: string | null
  running_state: 'running' | 'attention' | 'stopped' | 'maintenance' | 'idle'
  oee_pct: number | null
  target_qty: number
  actual_qty: number
  reject_qty: number
  downtime_minutes: number
}

const props = defineProps<{
  date: string
  lines: LineRow[]
}>()

const date = ref(props.date)

watch(date, debounce(() => {
  router.get(route('mes.dashboards.line'), { date: date.value }, { preserveState: true, replace: true })
}, 300))

const columns = [
  { key: 'code', label: 'Line' },
  { key: 'area_line', label: 'Area' },
  { key: 'running_state', label: 'State' },
  { key: 'oee_pct', label: 'OEE' },
  { key: 'target_qty', label: 'Target' },
  { key: 'actual_qty', label: 'Actual' },
  { key: 'reject_qty', label: 'Reject' },
  { key: 'downtime_minutes', label: 'Downtime (min)' },
]

const stateVariant = (state: string) =>
  state === 'running' ? 'success' : state === 'attention' ? 'warning' : state === 'stopped' ? 'danger' : state === 'maintenance' ? 'info' : 'neutral'

const pct = (value: number | null) => (value === null ? '—' : `${value}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="Line Dashboard" description="Per-line running state, OEE, target vs. actual, reject count, and downtime for the day (MES_SPECS.md §3T)." />

    <Panel class="mt-6 max-w-xs">
      <FormInput v-model="date" name="date" label="Day" type="date" />
    </Panel>

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="lines"
        row-key="work_center_id"
        sticky-header
        storage-key="mes.dashboards.line"
        empty-title="No discrete lines"
        empty-description="No discrete (assembly) work centers are set up yet."
      >
        <template #cell-code="{ item }">
          <span class="font-mono text-xs font-medium text-ink-900">{{ (item as LineRow).code }}</span>
          <span class="ml-1 text-xs text-ink-600">{{ (item as LineRow).name }}</span>
        </template>
        <template #cell-area_line="{ item }">
          <span class="text-xs text-ink-700">{{ (item as LineRow).area_line ?? '—' }}</span>
        </template>
        <template #cell-running_state="{ item }">
          <StatusBadge :status="(item as LineRow).running_state" :variant="stateVariant((item as LineRow).running_state)" />
        </template>
        <template #cell-oee_pct="{ item }">
          <span class="tabular-nums text-xs text-ink-700">{{ pct((item as LineRow).oee_pct) }}</span>
        </template>
        <template #cell-target_qty="{ item }">
          <span class="tabular-nums text-xs text-ink-700">{{ formatNumber((item as LineRow).target_qty) }}</span>
        </template>
        <template #cell-actual_qty="{ item }">
          <span class="tabular-nums text-xs text-ink-700">{{ formatNumber((item as LineRow).actual_qty) }}</span>
        </template>
        <template #cell-reject_qty="{ item }">
          <span class="tabular-nums text-xs text-ink-700">{{ formatNumber((item as LineRow).reject_qty) }}</span>
        </template>
        <template #cell-downtime_minutes="{ item }">
          <span class="tabular-nums text-xs text-ink-700">{{ (item as LineRow).downtime_minutes.toFixed(0) }}</span>
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
