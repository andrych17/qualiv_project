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
    // Schedule Task/Event status (§3B/§3C)
    scheduled: 'bg-signal-info/10 text-signal-info border-signal-info/25',
    cancelled: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // Accounting GL Journal / fiscal period status (§3C/§3O)
    posted: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    reversed: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    soft_closed: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    hard_closed: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // Accounting tax period filing status (§3M) — 'late' is a derived display state, never persisted
    filed: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    late: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // Accounting AR invoice/payment/credit note status (§3D)
    paid: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    partially_paid: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    void: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // Accounting §3Q bank statement line reconciliation status
    matched: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    unmatched: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    ignored: 'bg-surface-50 text-ink-600 border-border',
    // Inventory §3M serial number status
    in_stock: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    issued: 'bg-surface-50 text-ink-600 border-border',
    reserved: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    // Inventory §3N reservation status ('active' reuses the key above)
    fulfilled: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    released: 'bg-surface-50 text-ink-600 border-border',
    // Inventory §3O pick list / line status ('pending'/'in_progress' reuse the keys above)
    ready_for_packing: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    picked: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    // Inventory §3P pack list / shipment status ('pending' reuses the key above)
    packed: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    shipped: 'bg-signal-info/10 text-signal-info border-signal-info/25',
    delivered: 'bg-signal-success/10 text-signal-success border-signal-success/25',
    // Performance §3B Budget status ('draft'/'approved' reuse the keys above) + §3G Variance status
    submitted: 'bg-signal-info/10 text-signal-info border-signal-info/25',
    locked: 'bg-surface-50 text-ink-600 border-border',
    warning: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    breach: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
    // Performance §3E OKR Objective status ('on_track'/'completed' reuse the keys above)
    at_risk: 'bg-signal-warning/10 text-signal-warning border-signal-warning/25',
    off_track: 'bg-signal-danger/10 text-signal-danger border-signal-danger/25',
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
