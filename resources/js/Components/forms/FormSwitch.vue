<!-- ponytail: Reusable Toggle Switch component -->
<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    modelValue: boolean
    label?: string
    description?: string
    name?: string
    disabled?: boolean
  }>(),
  {
    label: '',
    description: '',
    disabled: false,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const toggle = () => {
  if (props.disabled) return
  emit('update:modelValue', !props.modelValue)
}
</script>

<template>
  <div class="flex items-start justify-between gap-4">
    <div v-if="label || description" class="space-y-0.5">
      <label
        v-if="label"
        @click="toggle"
        class="text-sm font-medium text-ink-900 cursor-pointer select-none"
        :class="{ 'opacity-60 cursor-not-allowed': disabled }"
      >
        {{ label }}
      </label>
      <p v-if="description" class="text-xs text-ink-600">
        {{ description }}
      </p>
    </div>

    <button
      type="button"
      role="switch"
      :aria-checked="modelValue"
      :disabled="disabled"
      @click="toggle"
      class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-ink-900/20"
      :class="[
        modelValue ? 'bg-accent' : 'bg-border',
        disabled ? 'opacity-50 cursor-not-allowed' : ''
      ]"
    >
      <span
        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
        :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
      />
    </button>
  </div>
</template>
