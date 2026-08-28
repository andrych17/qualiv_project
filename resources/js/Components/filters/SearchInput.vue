<!-- ponytail: Reusable SearchInput component -->
<script setup lang="ts">
import { Search } from 'lucide-vue-next'

withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
}>(), {
  placeholder: 'Search...',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <div class="relative">
    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-600" />
    <input
      :value="modelValue"
      :placeholder="placeholder"
      class="w-full rounded-md border border-border bg-surface-0 py-2 pl-9 pr-3 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </div>
</template>
