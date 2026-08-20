<!-- ponytail: DESIGN.md Status Badge — pill reserved for status; color + label -->
<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  status: string
  label?: string
}>()

const normalizedStatus = computed(() => props.status?.toLowerCase() ?? 'unknown')

const displayLabel = computed(() => {
  if (props.label) return props.label
  return normalizedStatus.value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
})

/** Maps domain status → semantic signal (DESIGN.md). */
const badgeClass = computed(() => {
  const map: Record<string, string> = {
    open: 'bg-signal-info/10 text-signal-info border-signal-info/25',
    active: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    approved: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    closed: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    completed: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    pending: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    in_progress: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    done: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    inactive: 'bg-surface-50 text-ink-600 border-border',
    archived: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    rejected: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    overdue: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // CRM Lead stages (§3D)
    new: 'bg-signal-info/10 text-signal-info border-signal-info/25',
    contacted: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    qualified: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    converted: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    disqualified: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // CRM After Sales Service status + SLA state (§3E)
    waiting_on_partner: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    resolved: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    breached: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    due_soon: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    on_track: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    // WNE Workflow Definition status (§3B)
    draft: 'bg-surface-50 text-ink-600 border-border',
    published: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    unpublished: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
  }

  return map[normalizedStatus.value] ?? 'bg-surface-50 text-ink-600 border-border'
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="badgeClass"
  >
    {{ displayLabel }}
  </span>
</template>
