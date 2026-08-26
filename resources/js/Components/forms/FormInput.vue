<!-- ponytail: Reusable FormInput component -->
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: string | number | null
  label: string
  name?: string
  type?: string
  placeholder?: string
  error?: string
  required?: boolean
}>(), {
  name: '',
  type: 'text',
  placeholder: '',
  required: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const inputId = computed(() => props.name ? `input-${props.name}` : undefined)
</script>

<template>
  <div class="space-y-1.5">
    <label :for="inputId" class="text-sm font-medium text-gray-700">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <input
      :id="inputId"
      :name="name"
      :type="type"
      :value="modelValue ?? ''"
      :placeholder="placeholder"
      class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
      :class="error ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="error" class="text-sm text-red-600">
      {{ error }}
    </p>
  </div>
</template>
