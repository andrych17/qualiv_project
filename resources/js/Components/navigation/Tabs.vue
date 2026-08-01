<!-- ponytail: Shared tab switcher (DESIGN.md §3 inventory). Content stays in the
     host page — this only renders the tab bar; the active panel is the host's
     `v-if`/`v-show` on the same model. -->
<script setup lang="ts">
defineProps<{
  tabs: Array<{ key: string; label: string; count?: number }>
  modelValue: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <div class="flex items-center gap-1 border-b border-border" role="tablist">
    <button
      v-for="tab in tabs"
      :key="tab.key"
      type="button"
      role="tab"
      :aria-selected="modelValue === tab.key"
      class="flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      :class="modelValue === tab.key ? 'border-accent text-ink-900' : 'border-transparent text-ink-600 hover:text-ink-900'"
      @click="emit('update:modelValue', tab.key)"
    >
      {{ tab.label }}
      <span
        v-if="tab.count !== undefined"
        class="rounded-full bg-surface-0 px-2 py-0.5 text-[11px] font-medium text-ink-600 ring-1 ring-border"
      >
        {{ tab.count }}
      </span>
    </button>
  </div>
</template>
