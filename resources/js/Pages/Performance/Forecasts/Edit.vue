<!-- ponytail: Forecast detail (§3H) — read-only, since a forecast is immutable once created;
     "Revise" (only offered on the latest version) is the sole way to change it. -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { formatNumber } from '@/Utils/formatters'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface LineWithVariance {
  id: number
  period_id: number
  period_label: string | null
  forecast_value: number
  variance: { actual_value: number; variance_pct: number | null; status: string } | null
}

const props = defineProps<{
  forecast: {
    id: number
    subject_label: string
    linked_label: string
    period_id: number
    period_label: string | null
    version_no: number
    is_latest: boolean
    notes: string | null
    series_id: number
    lines: LineWithVariance[]
  }
}>()

const { confirm } = useConfirm()

const confirmDelete = () => confirm({
  title: 'Delete this forecast?',
  variant: 'destructive',
  confirmText: 'Delete',
  onConfirm: () => router.delete(route('performance.forecasts.destroy', props.forecast.id)),
})
</script>

<template>
  <AppLayout>
    <PageHeader :title="forecast.linked_label" :description="`Version ${forecast.version_no} · ${forecast.subject_label} · ${forecast.period_label}`">
      <template #actions>
        <StatusBadge :status="forecast.is_latest ? 'active' : 'archived'" :label="forecast.is_latest ? 'Latest' : 'Superseded'" />
      </template>
    </PageHeader>

    <PerformanceSubNav active="forecasts" class="mt-6" />

    <Panel class="mt-6 max-w-4xl" title="Lines">
      <div class="space-y-3">
        <div
          v-for="line in forecast.lines"
          :key="line.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-3"
        >
          <div>
            <p class="text-sm font-semibold text-ink-900">{{ line.period_label }}</p>
            <p class="text-xs text-ink-600">Forecast {{ formatNumber(line.forecast_value) }}</p>
          </div>
          <div v-if="line.variance" class="flex items-center gap-3">
            <span class="text-sm text-ink-600">Actual/comparison {{ formatNumber(line.variance.actual_value) }}</span>
            <StatusBadge :status="line.variance.status" />
          </div>
          <span v-else class="text-xs text-ink-600">Nothing to compare yet</span>
        </div>

        <p v-if="forecast.lines.length === 0" class="text-sm text-ink-600">No lines on this forecast.</p>
      </div>
    </Panel>

    <div v-if="forecast.notes" class="mt-6 max-w-4xl text-sm text-ink-600">
      <span class="font-medium text-ink-900">Notes:</span> {{ forecast.notes }}
    </div>

    <div class="mt-6 flex max-w-4xl items-center justify-between">
      <Link :href="route('performance.forecasts.index', { series: forecast.series_id })" class="text-sm font-medium text-accent hover:underline">
        View version history
      </Link>
      <div class="flex items-center gap-3">
        <SecondaryButton v-if="forecast.version_no === 1 && forecast.is_latest" type="button" @click="confirmDelete">Delete</SecondaryButton>
        <PrimaryButton v-if="forecast.is_latest" :href="route('performance.forecasts.revise.form', forecast.id)">Revise</PrimaryButton>
      </div>
    </div>
  </AppLayout>
</template>
