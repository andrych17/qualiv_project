<!-- ponytail: Performance Main Dashboard (§3A) — read-only rollup over every other §3 engine;
     adds no tables of its own. One remaining documented scope gap: rows link straight to their
     own edit page instead of opening a trend drawer (period-over-period trending is a
     materially bigger feature than this rollup). Status Rail: danger = breach/off_track,
     warning = warning/at_risk, neutral = pending (no actual recorded yet). -->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import Tabs from '@/Components/navigation/Tabs.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import EmptyState from '@/Components/feedback/EmptyState.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { formatNumber } from '@/Utils/formatters'
import { AlertTriangle, Sparkles } from 'lucide-vue-next'

interface KpiRow { id: number; kpi_id: number; kpi_name: string | null; target_value: number; actual_value: number | null; variance_pct: number | null; status: string; href: string }
interface OkrRow { id: number; objective_text: string; status: string; progress: number | null; href: string }
interface BudgetRow { id: number; budget_id: number; budget_name: string | null; category: string; amount_planned: number; actual_value: number | null; variance_pct: number | null; status: string; href: string }
interface ScorecardRow { id: number; name: string; overall_score: number | null; scored_perspectives: number; total_perspectives: number; href: string }
interface NeedsAttentionItem { type: string; label: string; detail: string; rail: 'danger' | 'warning'; href: string }
interface AchievementItem { id: number; badge_name: string | null; badge_icon: string | null; context: string | null; earned_at_formatted: string | null }

const props = defineProps<{
  filters: { period_id: number | null; cycle_id: number | null; subject_type: 'company' | 'org_unit' | 'employee'; subject_id: number | null; perspective_id: number | null }
  periods: Array<{ id: number; label: string }>
  cycles: Array<{ id: number; label: string }>
  perspectives: Array<{ id: number; name: string }>
  orgUnits: Array<{ id: number; name: string }>
  employees: Array<{ id: number; full_name: string; employee_no: string }>
  metrics: { overall_scorecard_pct: number | null; budget_variance_pct: number | null; okrs_on_track: number; okrs_total: number; open_breaches: number }
  needsAttention: NeedsAttentionItem[]
  kpiRows: KpiRow[]
  okrRows: OkrRow[]
  budgetRows: BudgetRow[]
  scorecardRows: ScorecardRow[]
  recentAchievements: AchievementItem[]
}>()

const filters = ref({ ...props.filters })

watch(filters, () => {
  router.get(route('performance.dashboard'), { ...filters.value }, { preserveState: true, replace: true })
}, { deep: true })

const tab = ref<'scorecards' | 'kpis' | 'okrs' | 'budget' | 'needsAttention'>('scorecards')

const tabs = [
  { key: 'scorecards', label: 'Scorecards', count: props.scorecardRows.length },
  { key: 'kpis', label: 'KPIs', count: props.kpiRows.length },
  { key: 'okrs', label: 'OKRs', count: props.okrRows.length },
  { key: 'budget', label: 'Budget vs Actual', count: props.budgetRows.length },
  { key: 'needsAttention', label: 'Needs Attention', count: props.needsAttention.length },
] as const

const scorecardColumns = [
  { key: 'name', label: 'Name' },
  { key: 'overall_score', label: 'Overall score', align: 'right' as const },
  { key: 'coverage', label: 'Coverage' },
]
const kpiColumns = [
  { key: 'kpi_name', label: 'KPI' },
  { key: 'target_value', label: 'Target', align: 'right' as const },
  { key: 'actual_value', label: 'Actual', align: 'right' as const },
  { key: 'variance_pct', label: 'Variance', align: 'right' as const },
  { key: 'status', label: 'Status' },
]
const okrColumns = [
  { key: 'objective_text', label: 'Objective' },
  { key: 'progress', label: 'Progress', align: 'right' as const },
  { key: 'status', label: 'Status' },
]
const budgetColumns = [
  { key: 'budget_name', label: 'Budget' },
  { key: 'category', label: 'Category' },
  { key: 'amount_planned', label: 'Planned', align: 'right' as const },
  { key: 'actual_value', label: 'Actual', align: 'right' as const },
  { key: 'variance_pct', label: 'Variance', align: 'right' as const },
  { key: 'status', label: 'Status' },
]
const needsAttentionColumns = [
  { key: 'type', label: 'Type' },
  { key: 'label', label: 'Item' },
  { key: 'detail', label: 'Detail' },
]

const pctLabel = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${v}%`)
</script>

<template>
  <AppLayout>
    <PageHeader title="Performance Dashboard" description="Rollup of Scorecards, KPIs, OKRs, and Budget-vs-Actual (§3A).">
      <template #actions>
        <div class="flex flex-wrap items-end gap-2">
          <FormSelect v-model="filters.period_id" name="period_id" label="Period" :options="periods.map((p) => ({ label: p.label, value: p.id }))" />
          <FormSelect v-model="filters.cycle_id" name="cycle_id" label="OKR Cycle" :options="cycles.map((c) => ({ label: c.label, value: c.id }))" />
          <FormSelect v-model="filters.perspective_id" name="perspective_id" label="Perspective" placeholder="All" :options="perspectives.map((p) => ({ label: p.name, value: p.id }))" />
        </div>
      </template>
    </PageHeader>

    <PerformanceSubNav active="dashboard" class="mt-6" />

    <div class="mt-6 flex flex-wrap items-end gap-3">
      <FormRadioGroup
        v-model="filters.subject_type"
        name="subject_type"
        label="Subject"
        inline
        :options="[
          { label: 'Company', value: 'company' },
          { label: 'Org Unit', value: 'org_unit' },
          { label: 'Employee', value: 'employee' },
        ]"
      />
      <FormSelect
        v-if="filters.subject_type === 'org_unit'"
        v-model="filters.subject_id"
        name="subject_id"
        placeholder="Select an org unit…"
        :options="orgUnits.map((o) => ({ label: o.name, value: o.id }))"
      />
      <FormSelect
        v-else-if="filters.subject_type === 'employee'"
        v-model="filters.subject_id"
        name="subject_id"
        placeholder="Select an employee…"
        :options="employees.map((e) => ({ label: `${e.employee_no} — ${e.full_name}`, value: e.id }))"
      />
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
      <StatCard title="Overall Scorecard %" :value="metrics.overall_scorecard_pct === null ? '—' : `${metrics.overall_scorecard_pct}%`" icon="Gauge" />
      <StatCard title="Budget Variance %" :value="pctLabel(metrics.budget_variance_pct)" icon="Wallet" />
      <StatCard title="OKRs On-Track" :value="`${metrics.okrs_on_track} / ${metrics.okrs_total}`" icon="Target" />
      <StatCard title="Open Breaches" :value="formatNumber(metrics.open_breaches)" icon="CircleAlert" />
    </div>

    <Panel v-if="needsAttention.length > 0" title="Needs Attention" class="mt-6">
      <div class="divide-y divide-border">
        <Link
          v-for="(item, index) in needsAttention.slice(0, 8)"
          :key="index"
          :href="item.href"
          class="flex items-center gap-3 py-3 transition hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
          <span class="h-8 w-1 shrink-0 rounded-full" :class="item.rail === 'danger' ? 'bg-signal-danger' : 'bg-signal-warning'" />
          <AlertTriangle class="h-4 w-4 shrink-0" :class="item.rail === 'danger' ? 'text-signal-danger' : 'text-signal-warning'" />
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink-900">{{ item.label }}</p>
            <p class="text-xs text-ink-600">{{ item.detail }}</p>
          </div>
          <span class="shrink-0 rounded-full bg-surface-50 px-2 py-0.5 text-[11px] font-medium text-ink-600 ring-1 ring-border">{{ item.type }}</span>
        </Link>
      </div>
    </Panel>

    <div class="mt-6 space-y-4">
      <Tabs v-model="tab" :tabs="[...tabs]" />

      <DataTable
        v-if="tab === 'scorecards'"
        :columns="scorecardColumns"
        :items="scorecardRows"
        empty-title="No scorecards for this subject/period"
        empty-description="Build one from the Scorecards tab."
      >
        <template #cell-name="{ item }">
          <Link :href="(item as ScorecardRow).href" class="font-medium text-accent hover:underline">{{ (item as ScorecardRow).name }}</Link>
        </template>
        <template #cell-overall_score="{ item }">{{ (item as ScorecardRow).overall_score === null ? '—' : `${Math.round((item as ScorecardRow).overall_score as number)}%` }}</template>
        <template #cell-coverage="{ item }">{{ (item as ScorecardRow).scored_perspectives }} / {{ (item as ScorecardRow).total_perspectives }} perspectives scored</template>
      </DataTable>

      <DataTable
        v-else-if="tab === 'kpis'"
        :columns="kpiColumns"
        :items="kpiRows"
        status-rail-key="status"
        empty-title="No KPI targets for this subject/period"
      >
        <template #cell-kpi_name="{ item }">
          <Link :href="(item as KpiRow).href" class="font-medium text-accent hover:underline">{{ (item as KpiRow).kpi_name }}</Link>
        </template>
        <template #cell-target_value="{ item }">{{ formatNumber((item as KpiRow).target_value) }}</template>
        <template #cell-actual_value="{ item }">{{ (item as KpiRow).actual_value === null ? '—' : formatNumber((item as KpiRow).actual_value as number) }}</template>
        <template #cell-variance_pct="{ item }">{{ pctLabel((item as KpiRow).variance_pct) }}</template>
        <template #cell-status="{ item }"><StatusBadge :status="(item as KpiRow).status" /></template>
      </DataTable>

      <DataTable
        v-else-if="tab === 'okrs'"
        :columns="okrColumns"
        :items="okrRows"
        status-rail-key="status"
        empty-title="No objectives for this subject/cycle"
      >
        <template #cell-objective_text="{ item }">
          <Link :href="(item as OkrRow).href" class="font-medium text-accent hover:underline">{{ (item as OkrRow).objective_text }}</Link>
        </template>
        <template #cell-progress="{ item }">{{ (item as OkrRow).progress === null ? '—' : `${Math.round((item as OkrRow).progress as number)}%` }}</template>
        <template #cell-status="{ item }"><StatusBadge :status="(item as OkrRow).status" /></template>
      </DataTable>

      <DataTable
        v-else-if="tab === 'budget'"
        :columns="budgetColumns"
        :items="budgetRows"
        status-rail-key="status"
        empty-title="No budget lines for this subject/period"
      >
        <template #cell-budget_name="{ item }">
          <Link :href="(item as BudgetRow).href" class="font-medium text-accent hover:underline">{{ (item as BudgetRow).budget_name }}</Link>
        </template>
        <template #cell-amount_planned="{ item }">{{ formatNumber((item as BudgetRow).amount_planned) }}</template>
        <template #cell-actual_value="{ item }">{{ (item as BudgetRow).actual_value === null ? '—' : formatNumber((item as BudgetRow).actual_value as number) }}</template>
        <template #cell-variance_pct="{ item }">{{ pctLabel((item as BudgetRow).variance_pct) }}</template>
        <template #cell-status="{ item }"><StatusBadge :status="(item as BudgetRow).status" /></template>
      </DataTable>

      <DataTable
        v-else
        :columns="needsAttentionColumns"
        :items="needsAttention"
        row-key="label"
        empty-title="Nothing needs attention"
        empty-description="Every KPI, Budget line, and OKR in scope is on track."
      >
        <template #cell-label="{ item }">
          <Link :href="(item as NeedsAttentionItem).href" class="font-medium text-accent hover:underline">{{ (item as NeedsAttentionItem).label }}</Link>
        </template>
      </DataTable>
    </div>

    <Panel title="Recent Achievements" class="mt-6">
      <EmptyState
        v-if="recentAchievements.length === 0"
        :icon="Sparkles"
        title="No achievements yet"
        description="Badges earned by this subject will show up here."
      />
      <ul v-else class="divide-y divide-border">
        <li v-for="item in recentAchievements" :key="item.id" class="flex items-center justify-between gap-4 py-3">
          <div class="flex items-center gap-3">
            <Sparkles class="h-5 w-5 shrink-0 text-accent" />
            <div>
              <p class="text-sm font-medium text-ink-900">{{ item.badge_name }}</p>
              <p v-if="item.context" class="text-xs text-ink-600">{{ item.context }}</p>
            </div>
          </div>
          <span class="shrink-0 text-xs text-ink-600">{{ item.earned_at_formatted }}</span>
        </li>
      </ul>
    </Panel>
  </AppLayout>
</template>
