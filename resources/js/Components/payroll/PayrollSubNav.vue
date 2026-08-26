<!-- ponytail: Payroll SubNav switcher — one Payroll menu row covers all Payroll sub-pages. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'runs' | 'profiles' | 'components' | 'structures' | 'reimbursements'
}>()
</script>

<template>
  <div class="flex items-center gap-1 overflow-x-auto border-b border-border" role="tablist">
    <Link
      :href="route('payroll.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('payroll.runs.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'runs' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Payroll Runs
    </Link>
    <Link
      :href="route('payroll.profiles.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'profiles' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Employee Profiles
    </Link>
    <Link
      :href="route('payroll.components.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'components' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Components
    </Link>
    <Link
      :href="route('payroll.structures.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'structures' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Salary Structures
    </Link>
    <Link
      :href="route('payroll.reimbursements.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'reimbursements' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Reimbursements
    </Link>
  </div>
</template>
