<!-- ponytail: Reusable FormSelect component with theme tokens -->
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  label?: string
  name?: string
  options: Array<{ label: string; value: string | number }>
  placeholder?: string
  error?: string
  required?: boolean
  disabled?: boolean
}>(), {
  label: '',
  name: '',
  placeholder: 'Select option...',
  required: false,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const selectId = computed(() => props.name ? `select-${props.name}` : undefined)
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="selectId" class="text-sm font-medium text-ink-900">
      {{ label }}
      <span v-if="required" class="text-signal-danger">*</span>
    </label>

    <select
      :id="selectId"
      :name="name"
      :value="modelValue ?? ''"
      :disabled="disabled"
      class="w-full rounded-md border border-border bg-surface-0 pl-3 pr-10 py-2 text-sm text-ink-900 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20 truncate"
      :class="[
        error ? 'border-signal-danger focus:border-signal-danger focus:ring-signal-danger/20' : '',
        disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : '',
      ]"
      @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
      <option value="" disabled selected class="bg-surface-0 text-ink-600">{{ placeholder }}</option>
      <option
        v-for="opt in options"
        :key="opt.value"
        :value="opt.value"
        class="bg-surface-0 text-ink-900"
      >
        {{ opt.label }}
      </option>
    </select>

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
