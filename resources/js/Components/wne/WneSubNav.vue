<!-- ponytail: Dashboard/Workflows/My Approvals/Templates/Preferences/Dead Letters switcher —
     one WNE menu row covers all six routes, so the second-level nav lives here rather than as
     separate sidebar entries (SysConfigSeeder), same convention as CRM's CrmSubNav. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'workflows' | 'my-tasks' | 'templates' | 'preferences' | 'dead-letters'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
    <Link
      :href="route('wne.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('wne.workflows.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'workflows' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Workflows
    </Link>
    <Link
      :href="route('wne.my-tasks.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'my-tasks' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      My Approvals
    </Link>
    <Link
      :href="route('wne.templates.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'templates' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Templates
    </Link>
    <Link
      :href="route('wne.preferences.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'preferences' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Preferences
    </Link>
    <Link
      :href="route('wne.dead-letters.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'dead-letters' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dead Letters
    </Link>
  </div>
</template>
