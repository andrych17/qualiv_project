<!-- ponytail: Performance module second-level subnav — one PERFORMANCE menu row covers every
     Performance route (same pattern as InventorySubNav). -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'kpiDefinitions' | 'perspectives' | 'periods' | 'targets' | 'kpiValues' | 'budgets' | 'budgetCategoryAccounts' | 'forecasts' | 'okrObjectives' | 'okrCycles' | 'scorecards' | 'badgeDefinitions' | 'achievements'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border overflow-x-auto scrollbar-none" role="tablist">
    <Link
      :href="route('performance.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('performance.kpiDefinitions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'kpiDefinitions' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      KPI Definitions
    </Link>
    <Link
      :href="route('performance.targets.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'targets' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Targets
    </Link>
    <Link
      :href="route('performance.kpiValues.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'kpiValues' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Actuals
    </Link>
    <Link
      :href="route('performance.budgets.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'budgets' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Budgets
    </Link>
    <Link
      :href="route('performance.budgetCategoryAccounts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'budgetCategoryAccounts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Budget GL Mapping
    </Link>
    <Link
      :href="route('performance.forecasts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'forecasts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Forecasts
    </Link>
    <Link
      :href="route('performance.okrObjectives.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'okrObjectives' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      OKRs
    </Link>
    <Link
      :href="route('performance.okrCycles.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'okrCycles' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      OKR Cycles
    </Link>
    <Link
      :href="route('performance.scorecards.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'scorecards' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Scorecards
    </Link>
    <Link
      :href="route('performance.achievements.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'achievements' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Achievements
    </Link>
    <Link
      :href="route('performance.badgeDefinitions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'badgeDefinitions' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Badges
    </Link>
    <Link
      :href="route('performance.perspectives.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'perspectives' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Perspectives
    </Link>
    <Link
      :href="route('performance.periods.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'periods' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Periods
    </Link>
  </div>
</template>
