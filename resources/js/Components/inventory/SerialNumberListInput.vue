<!-- ponytail: §3M — a serial-tracked line names its exact unit(s) instead of typing a
     quantity; qty is derived (count of entries) by the parent line component, not here. -->
<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next'

const props = defineProps<{
  modelValue: string[]
  placeholder?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

const addEntry = () => emit('update:modelValue', [...props.modelValue, ''])
const removeEntry = (index: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
const setEntry = (index: number, value: string) => {
  const next = props.modelValue.map((v, i) => (i === index ? value : v))
  emit('update:modelValue', next)
}
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex items-center justify-between">
      <label class="text-sm font-medium text-ink-900">Serial numbers ({{ modelValue.length }})</label>
      <button
        v-if="!disabled"
        type="button"
        class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline"
        @click="addEntry"
      >
        <Plus class="h-3.5 w-3.5" /> Add serial
      </button>
    </div>

    <p v-if="modelValue.length === 0" class="text-sm text-ink-600">No serials entered yet.</p>

    <div v-for="(value, index) in modelValue" :key="index" class="flex items-center gap-2">
      <input
        :value="value"
        :placeholder="placeholder ?? 'Serial number'"
        :disabled="disabled"
        class="w-full rounded-sm border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 shadow-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:bg-surface-50 disabled:text-ink-600"
        @input="setEntry(index, ($event.target as HTMLInputElement).value)"
      />
      <button v-if="!disabled" type="button" class="shrink-0 text-signal-danger" @click="removeEntry(index)">
        <Trash2 class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
