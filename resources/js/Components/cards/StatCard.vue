<!-- ponytail: Dashboard stat — serif number per DESIGN.md -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import * as icons from 'lucide-vue-next'

const props = defineProps<{
  title: string
  value: string
  description?: string
  icon?: string
  href?: string | null
  /** Rows added in the trailing 30 days. Omit when the source table has no created_at. */
  delta?: number | null
}>()

const getIcon = (name?: string) => {
  if (!name) return icons.HelpCircle
  return (icons as Record<string, unknown>)[name] as typeof icons.HelpCircle || icons.HelpCircle
}
</script>

<template>
  <component
    :is="href ? Link : 'div'"
    :href="href || undefined"
    class="flex items-center justify-between rounded-md border border-border bg-surface-0 p-6 shadow-sm"
    :class="href ? 'transition-colors hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent' : ''"
  >
    <div class="space-y-1">
      <p class="text-sm font-medium text-ink-600">{{ title }}</p>
      <div class="flex items-baseline gap-2">
        <p class="font-serif text-3xl font-semibold tabular-nums text-ink-900">{{ value }}</p>
        <span
          v-if="delta !== undefined && delta !== null"
          class="text-xs font-semibold tabular-nums"
          :class="delta > 0 ? 'text-signal-success' : 'text-ink-500'"
          :title="`${delta} baru dalam 30 hari terakhir`"
        >
          {{ delta > 0 ? `+${delta}` : delta }}
          <span class="font-normal text-ink-500">/30h</span>
        </span>
      </div>
      <p v-if="description" class="text-xs text-ink-600">{{ description }}</p>
    </div>
    <div
      v-if="icon"
      class="flex h-12 w-12 items-center justify-center rounded-md border border-border bg-surface-50 text-ink-600"
    >
      <component :is="getIcon(icon)" class="h-6 w-6" />
    </div>
  </component>
</template>
