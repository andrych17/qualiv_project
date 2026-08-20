<!-- ponytail: WNE §3B read-only node/arrow preview. Pure layout math (BFS level = column,
     order-within-level = row) — no DOM measurement needed, so it's cheap and stays correct
     as steps/transitions change. Future canvas (§2 deferred) replaces this, not the schema. -->
<script setup lang="ts">
import { computed } from 'vue'

type Step = { id: number; step_code: string; type: string; is_entry_step: boolean }
type Transition = { id: number; from_step_id: number; to_step_id: number; condition_expression: Record<string, unknown> | null; seq: number }

const props = defineProps<{
  steps: Step[]
  transitions: Transition[]
}>()

const BOX_W = 168
const BOX_H = 56
const GAP_X = 96
const GAP_Y = 28
const PAD = 24

type Positioned = Step & { x: number; y: number; level: number }

const layout = computed(() => {
  const byId = new Map(props.steps.map((s) => [s.id, s]))
  const outgoing = new Map<number, Transition[]>()
  for (const t of props.transitions) {
    if (!outgoing.has(t.from_step_id)) outgoing.set(t.from_step_id, [])
    outgoing.get(t.from_step_id)!.push(t)
  }

  const entry = props.steps.find((s) => s.is_entry_step)
  const level = new Map<number, number>()

  if (entry) {
    level.set(entry.id, 0)
    const queue = [entry.id]
    while (queue.length) {
      const current = queue.shift()!
      const currentLevel = level.get(current)!
      for (const t of outgoing.get(current) ?? []) {
        if (!level.has(t.to_step_id) && byId.has(t.to_step_id)) {
          level.set(t.to_step_id, currentLevel + 1)
          queue.push(t.to_step_id)
        }
      }
    }
  }

  const maxReachedLevel = Math.max(0, ...Array.from(level.values()))
  const unreachedLevel = maxReachedLevel + 1

  const rowCounters = new Map<number, number>()
  const positioned: Positioned[] = props.steps.map((step) => {
    const stepLevel = level.get(step.id) ?? unreachedLevel
    const row = rowCounters.get(stepLevel) ?? 0
    rowCounters.set(stepLevel, row + 1)
    return {
      ...step,
      level: stepLevel,
      x: PAD + stepLevel * (BOX_W + GAP_X),
      y: PAD + row * (BOX_H + GAP_Y),
    }
  })

  const maxRows = Math.max(1, ...Array.from(rowCounters.values()))
  const maxLevel = Math.max(0, ...positioned.map((p) => p.level))

  return {
    steps: positioned,
    byId: new Map(positioned.map((p) => [p.id, p])),
    hasUnreached: level.size < props.steps.length,
    width: PAD * 2 + (maxLevel + 1) * BOX_W + maxLevel * GAP_X,
    height: PAD * 2 + maxRows * BOX_H + (maxRows - 1) * GAP_Y,
  }
})

const conditionLabel = (t: Transition, isOnlyTransition: boolean): string => {
  if (!t.condition_expression) return isOnlyTransition ? '' : 'else'
  const { field, op, value } = t.condition_expression as { field?: string; op?: string; value?: unknown }
  return `${field ?? '?'} ${op ?? '?'} ${value ?? '?'}`
}

const edges = computed(() => {
  const outgoingCount = new Map<number, number>()
  for (const t of props.transitions) {
    outgoingCount.set(t.from_step_id, (outgoingCount.get(t.from_step_id) ?? 0) + 1)
  }

  return props.transitions
    .map((t) => {
      const from = layout.value.byId.get(t.from_step_id)
      const to = layout.value.byId.get(t.to_step_id)
      if (!from || !to) return null

      const x1 = from.x + BOX_W
      const y1 = from.y + BOX_H / 2
      const x2 = to.x
      const y2 = to.y + BOX_H / 2
      const midX = (x1 + x2) / 2

      return {
        id: t.id,
        path: `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`,
        labelX: midX,
        labelY: (y1 + y2) / 2 - 6,
        label: conditionLabel(t, outgoingCount.get(t.from_step_id) === 1),
      }
    })
    .filter((e): e is NonNullable<typeof e> => e !== null)
})

const typeLabel = (type: string) => type.replace(/_/g, ' ')
</script>

<template>
  <div v-if="steps.length === 0" class="py-8 text-center text-sm text-ink-600">
    Add steps to see the process preview.
  </div>
  <div v-else class="overflow-auto rounded-md border border-border bg-surface-50 p-2">
    <svg :width="layout.width" :height="layout.height" :viewBox="`0 0 ${layout.width} ${layout.height}`">
      <defs>
        <marker id="wne-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
          <path d="M0,0 L8,4 L0,8 Z" class="fill-ink-600" />
        </marker>
      </defs>

      <g v-for="edge in edges" :key="edge.id">
        <path :d="edge.path" fill="none" class="stroke-ink-600" stroke-width="1.5" marker-end="url(#wne-arrow)" />
        <text v-if="edge.label" :x="edge.labelX" :y="edge.labelY" text-anchor="middle" class="fill-ink-600 text-[10px]">
          {{ edge.label }}
        </text>
      </g>

      <g v-for="step in layout.steps" :key="step.id">
        <rect
          :x="step.x"
          :y="step.y"
          :width="BOX_W"
          :height="BOX_H"
          rx="8"
          class="stroke-border"
          :class="step.is_entry_step ? 'fill-accent/10 stroke-accent' : 'fill-surface-0'"
          stroke-width="1.5"
        />
        <text :x="step.x + 10" :y="step.y + 22" class="fill-ink-900 text-[11px] font-semibold">
          {{ step.step_code }}
        </text>
        <text :x="step.x + 10" :y="step.y + 38" class="fill-ink-600 text-[10px]">
          {{ typeLabel(step.type) }}
        </text>
      </g>
    </svg>
    <p v-if="layout.hasUnreached" class="mt-2 text-xs text-signal-warning">
      Some steps aren't reachable from the entry step yet — publishing will be blocked until they are.
    </p>
  </div>
</template>
