<!-- ponytail: Scorecard Viewer (§3F) — classic Balanced-Scorecard grid, rows grouped by
     perspective, everything computed live by ScorecardScoringService (never stored). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { formatNumber } from '@/Utils/formatters'

interface ScoredItem {
  id: number
  label: string
  type: 'kpi' | 'okr'
  weight: number
  actual: number | null
  target: number | null
  status: string | null
  score: number | null
}

interface ScoredPerspective {
  perspective_id: number
  perspective_name: string | null
  items: ScoredItem[]
  score: number | null
  scored_count: number
  total_count: number
}

const props = defineProps<{
  scorecard: { id: number; name: string; subject_label: string; period_label: string | null }
  scored: {
    perspectives: ScoredPerspective[]
    overall_score: number | null
    scored_perspectives: number
    total_perspectives: number
  }
}>()
</script>

<template>
  <AppLayout>
    <PageHeader :title="scorecard.name" :description="`${scorecard.subject_label} · ${scorecard.period_label}`">
      <template #actions>
        <PrimaryButton :href="route('performance.scorecards.edit', scorecard.id)">Edit weights</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="scorecards" class="mt-6" />

    <div class="mt-6 max-w-md">
      <StatCard
        title="Overall weighted score"
        :value="scored.overall_score === null ? '—' : `${Math.round(scored.overall_score)}%`"
        :description="`${scored.scored_perspectives} of ${scored.total_perspectives} perspectives scored`"
      />
    </div>

    <div class="mt-6 space-y-6">
      <Panel
        v-for="perspective in scored.perspectives"
        :key="perspective.perspective_id"
        :title="perspective.perspective_name ?? 'Unknown perspective'"
        :subtitle="`${perspective.scored_count} of ${perspective.total_count} items scored`"
      >
        <template #actions>
          <span class="font-serif text-lg font-semibold text-ink-900">
            {{ perspective.score === null ? '—' : `${Math.round(perspective.score)}%` }}
          </span>
        </template>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs font-semibold uppercase tracking-wide text-ink-600">
                <th class="py-2 pr-3">Item</th>
                <th class="py-2 pr-3">Type</th>
                <th class="py-2 pr-3 text-right">Weight</th>
                <th class="py-2 pr-3 text-right">Actual</th>
                <th class="py-2 pr-3 text-right">Target</th>
                <th class="py-2 pr-3 text-right">Score</th>
                <th class="py-2 pr-3">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in perspective.items" :key="item.id" class="border-b border-border last:border-b-0">
                <td class="py-2 pr-3 font-medium text-ink-900">{{ item.label }}</td>
                <td class="py-2 pr-3 text-ink-600">{{ item.type === 'kpi' ? 'KPI' : 'OKR' }}</td>
                <td class="py-2 pr-3 text-right">{{ item.weight }}%</td>
                <td class="py-2 pr-3 text-right">{{ item.actual === null ? '—' : formatNumber(item.actual) }}</td>
                <td class="py-2 pr-3 text-right">{{ item.target === null ? '—' : formatNumber(item.target) }}</td>
                <td class="py-2 pr-3 text-right">{{ item.score === null ? '—' : `${Math.round(item.score)}%` }}</td>
                <td class="py-2 pr-3">
                  <StatusBadge v-if="item.status" :status="item.status" />
                  <span v-else class="text-xs text-ink-600">No data yet</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Panel>

      <p v-if="scored.perspectives.length === 0" class="text-sm text-ink-600">
        This scorecard has no items yet. <Link :href="route('performance.scorecards.edit', scorecard.id)" class="text-accent hover:underline">Add some.</Link>
      </p>
    </div>
  </AppLayout>
</template>
