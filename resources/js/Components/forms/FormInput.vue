<!-- ponytail: Reusable FormInput component with theme tokens -->
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  label?: string
  name?: string
  type?: string
  placeholder?: string
  error?: string
  required?: boolean
  disabled?: boolean
}>(), {
  label: '',
  name: '',
  type: 'text',
  placeholder: '',
  required: false,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const inputId = computed(() => props.name ? `input-${props.name}` : undefined)
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" :for="inputId" class="text-sm font-medium text-ink-900">
      {{ label }}
      <span v-if="required" class="text-signal-danger">*</span>
    </label>

    <input
      :id="inputId"
      :name="name"
      :type="type"
      :value="modelValue ?? ''"
      :placeholder="placeholder"
      :disabled="disabled"
      class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
      :class="[
        error ? 'border-signal-danger focus:border-signal-danger focus:ring-signal-danger/20' : '',
        disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : '',
      ]"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
