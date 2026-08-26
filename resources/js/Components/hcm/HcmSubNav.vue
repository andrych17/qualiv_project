<!-- ponytail: HCM SubNav switcher — one HCM menu row covers all HCM routes. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'employees' | 'org' | 'positions' | 'contracts' | 'attendance' | 'shifts' | 'leave'
}>()
</script>

<template>
  <div class="flex items-center gap-1 overflow-x-auto border-b border-border" role="tablist">
    <Link
      :href="route('hcm.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('hcm.employees.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'employees' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Employees
    </Link>
    <Link
      :href="route('hcm.orgUnits.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'org' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Org Units
    </Link>
    <Link
      :href="route('hcm.positions.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'positions' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Positions
    </Link>
    <Link
      :href="route('hcm.contracts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'contracts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Contracts
    </Link>
    <Link
      :href="route('hcm.attendance.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'attendance' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Attendance
    </Link>
    <Link
      :href="route('hcm.shifts.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'shifts' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Shifts
    </Link>
    <Link
      :href="route('hcm.leave.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition whitespace-nowrap"
      :class="active === 'leave' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Leave
    </Link>
  </div>
</template>
