<!-- ponytail: Dashboard/Tasks/Events/Resources switcher — one SCHEDULE menu row covers all
     Schedule routes, same in-page sub-nav convention as CrmSubNav/WneSubNav. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  active: 'dashboard' | 'tasks' | 'events' | 'resources'
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
    <Link
      :href="route('schedule.dashboard')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'dashboard' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Dashboard
    </Link>
    <Link
      :href="route('schedule.tasks.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'tasks' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Tasks
    </Link>
    <Link
      :href="route('schedule.events.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'events' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Events
    </Link>
    <Link
      :href="route('schedule.resources.index')"
      role="tab"
      class="border-b-2 px-3 py-2 text-sm font-medium transition"
      :class="active === 'resources' ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
    >
      Resources
    </Link>
  </div>
</template>
