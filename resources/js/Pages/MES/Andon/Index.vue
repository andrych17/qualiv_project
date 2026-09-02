<!-- ponytail: Alerts & Andon (MES_SPECS.md §3R, Phase 3 — built now per explicit override).
     The board itself is a pure read model (no stored state); alert delivery/history lives in
     mes_andon_alerts and is fired by the mes:check-andon-alerts sweep, not from this page. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import { debounce } from '@/Composables/debounce'

interface BoardRow {
  machine_id: number
  code: string
  name: string
  work_center_id: number | null
  work_center_code: string | null
  status: string
  andon_state: 'running' | 'attention' | 'stopped' | 'maintenance'
}

const props = defineProps<{
  board: BoardRow[]
  workCenterId: number | null
  workCenters: Array<{ value: number; label: string }>
}>()

const workCenterId = ref<number | null>(props.workCenterId)

watch(workCenterId, debounce(() => {
  router.get(route('mes.andon.index'), { work_center_id: workCenterId.value }, { preserveState: true, replace: true })
}, 300))

const stateVariant = (state: string) =>
  state === 'running' ? 'success' : state === 'attention' ? 'warning' : state === 'stopped' ? 'danger' : 'info'
</script>

<template>
  <AppLayout>
    <PageHeader title="Andon Board" description="Live equipment status derived from machine state, open downtime, and open Andon alerts (MES_SPECS.md §3R) — nothing stored here. Alert delivery to WNE runs on its own five-minute sweep." />

    <div class="mt-6 max-w-xs">
      <FormSelect v-model="workCenterId" name="work_center_id" label="Work Center (all if blank)" :options="workCenters" />
    </div>

    <EmptyState
      v-if="board.length === 0"
      class="mt-6"
      title="No machines"
      description="No machines are registered for this filter yet."
    />

    <div v-else class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="row in board"
        :key="row.machine_id"
        class="rounded-lg border border-border bg-surface p-4"
        :class="{
          'border-signal-success/40': row.andon_state === 'running',
          'border-signal-warning/40': row.andon_state === 'attention',
          'border-signal-danger/40': row.andon_state === 'stopped',
          'border-signal-info/40': row.andon_state === 'maintenance',
        }"
      >
        <div class="flex items-start justify-between">
          <div>
            <div class="font-mono text-xs font-medium text-ink-900">{{ row.code }}</div>
            <div class="text-sm text-ink-700">{{ row.name }}</div>
            <div class="mt-1 text-xs text-ink-500">{{ row.work_center_code ?? '—' }}</div>
          </div>
          <StatusBadge :status="row.andon_state" :variant="stateVariant(row.andon_state)" />
        </div>
      </div>
    </div>
  </AppLayout>
</template>
