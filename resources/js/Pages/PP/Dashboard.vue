<!-- ponytail: Production Planning Dashboard (PP_SPECS.md §3O) — pure read model composed from
     StatCard/Panel only (CLAUDE.md §9D); no dashboard-only storage, no new bar-chart component
     (a couple of inline divs with a computed width isn't the kind of interactive primitive
     CLAUDE.md §9D's "never invent one for a single feature" rule is about — same posture
     CapacityPlans/Index.vue's own docblock took translating this spec's ASCII mockup). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import { formatNumber } from '@/Utils/formatters'

interface CapacityBar {
  label: string
  load_pct: number
  overloaded: boolean
}

const props = defineProps<{
  period_label: string
  demand_qty: number
  planned_qty: number
  gap_qty: number
  capacity_pct: number | null
  material_pct: number | null
  on_time_pct: number | null
  capacity_bars: CapacityBar[]
  exception_counts: Record<string, number>
  orders_ready_count: number
}>()

const pct = (value: number | null) => (value === null ? '—' : `${value}%`)
const formatExceptionLabel = (type: string) => type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
</script>

<template>
  <AppLayout>
    <PageHeader :title="`Production Plan — ${period_label}`" description="Demand vs. plan, capacity, material availability, and open exceptions — a read model over §3B/§3D/§3F/§3M, nothing stored here (§3O)." />

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Demand" :value="`${formatNumber(demand_qty)} units`" icon="TrendingUp" />
      <StatCard title="Planned" :value="`${formatNumber(planned_qty)} units`" icon="ClipboardList" :href="route('pp.plannedOrders.index')" />
      <StatCard
        title="Gap"
        :value="`${formatNumber(Math.abs(gap_qty))} units`"
        :description="gap_qty > 0 ? 'unmet demand' : gap_qty < 0 ? 'planned above demand' : 'fully covered'"
        icon="Scale"
      />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <StatCard title="Capacity" :value="pct(capacity_pct)" description="average of each dimension's worst-case load" icon="Gauge" :href="route('pp.capacityPlans.index')" />
      <StatCard title="Material" :value="pct(material_pct)" description="products without an open shortage" icon="Boxes" :href="route('pp.exceptions.index')" />
      <StatCard title="On-time" :value="pct(on_time_pct)" description="open orders not flagged late" icon="Clock" :href="route('pp.plannedOrders.index')" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Panel title="Capacity" subtitle="Worst-case load per resource group or resource, current period">
        <div v-if="capacity_bars.length" class="space-y-3">
          <div v-for="bar in capacity_bars" :key="bar.label" class="flex items-center gap-3">
            <span class="w-28 shrink-0 truncate text-sm text-ink-700" :title="bar.label">{{ bar.label }}</span>
            <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-surface-100">
              <div
                class="h-full rounded-full"
                :class="bar.overloaded ? 'bg-signal-danger' : 'bg-accent'"
                :style="{ width: `${Math.min(bar.load_pct, 100)}%` }"
              />
            </div>
            <span class="w-14 shrink-0 text-right text-sm font-medium tabular-nums" :class="bar.overloaded ? 'text-signal-danger' : 'text-ink-900'">
              {{ bar.load_pct }}%
            </span>
            <AlertTriangle v-if="bar.overloaded" class="h-4 w-4 shrink-0 text-signal-danger" />
          </div>
        </div>
        <p v-else class="text-sm text-ink-600">No capacity plans cover the current period yet.</p>
        <div class="mt-4 border-t border-border pt-3 text-right">
          <Link :href="route('pp.capacityPlans.index')" class="text-xs font-semibold text-accent hover:underline">View capacity plans →</Link>
        </div>
      </Panel>

      <Panel title="Exceptions" subtitle="Open + acknowledged, by type">
        <div v-if="Object.keys(exception_counts).length" class="divide-y divide-border">
          <Link
            v-for="(count, type) in exception_counts"
            :key="type"
            :href="route('pp.exceptions.index', { exception_type: type })"
            class="flex items-center justify-between gap-3 py-2.5 transition hover:bg-surface-50"
          >
            <span class="flex items-center gap-2 text-sm text-ink-900">
              <AlertTriangle class="h-4 w-4 shrink-0 text-signal-warning" />
              {{ formatExceptionLabel(String(type)) }}
            </span>
            <span class="font-serif text-lg font-semibold tabular-nums text-ink-900">{{ count }}</span>
          </Link>
        </div>
        <p v-else class="flex items-center gap-2 text-sm text-ink-600">
          <CheckCircle2 class="h-4 w-4 text-signal-success" /> No open exceptions.
        </p>
        <div class="mt-4 flex items-center justify-between border-t border-border pt-3">
          <span class="flex items-center gap-2 text-sm text-ink-700">
            <CheckCircle2 class="h-4 w-4 text-signal-success" />
            {{ formatNumber(orders_ready_count) }} orders ready
          </span>
          <Link :href="route('pp.exceptions.index')" class="text-xs font-semibold text-accent hover:underline">View exception center →</Link>
        </div>
      </Panel>
    </div>
  </AppLayout>
</template>
