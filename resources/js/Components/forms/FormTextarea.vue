<!-- ponytail: Reusable Textarea component with theme tokens -->
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | null
    label?: string
    name: string
    placeholder?: string
    rows?: number
    error?: string
    required?: boolean
    disabled?: boolean
    maxLength?: number
  }>(),
  {
    label: '',
    placeholder: '',
    rows: 4,
    required: false,
    disabled: false,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const textareaId = computed(() => `textarea-${props.name}`)
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex items-center justify-between">
      <label v-if="label" :for="textareaId" class="text-sm font-medium text-ink-900">
        {{ label }}
        <span v-if="required" class="text-signal-danger">*</span>
      </label>
      <span v-if="maxLength" class="text-xs text-ink-600 font-mono">
        {{ (modelValue || '').length }}/{{ maxLength }}
      </span>
    </div>

    <textarea
      :id="textareaId"
      :name="name"
      :rows="rows"
      :value="modelValue ?? ''"
      :placeholder="placeholder"
      :disabled="disabled"
      :maxlength="maxLength"
      class="w-full rounded-md border border-border bg-surface-0 px-3 py-2 text-sm text-ink-900 placeholder:text-ink-600/60 shadow-xs outline-none transition focus:border-accent focus:ring-2 focus:ring-accent/20"
      :class="[
        error ? 'border-signal-danger focus:border-signal-danger focus:ring-signal-danger/20' : '',
        disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : ''
      ]"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    />

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
