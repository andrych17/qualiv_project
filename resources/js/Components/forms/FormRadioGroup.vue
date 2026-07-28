<!-- ponytail: Reusable Radio Group component -->
<script setup lang="ts">
export type RadioOption = {
  label: string
  value: string | number
  description?: string
}

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null
    label?: string
    name: string
    options: RadioOption[]
    error?: string
    required?: boolean
    disabled?: boolean
    inline?: boolean
  }>(),
  {
    label: '',
    required: false,
    disabled: false,
    inline: false,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const select = (value: string | number) => {
  if (props.disabled) return
  emit('update:modelValue', value)
}
</script>

<template>
  <div class="space-y-2">
    <label v-if="label" class="text-sm font-medium text-ink-900 block">
      {{ label }}
      <span v-if="required" class="text-signal-danger">*</span>
    </label>

    <div
      class="gap-3"
      :class="inline ? 'flex flex-wrap items-center' : 'space-y-2'"
    >
      <div
        v-for="opt in options"
        :key="opt.value"
        @click="select(opt.value)"
        class="flex items-start gap-3 rounded-md border border-border bg-white p-3 cursor-pointer transition-colors hover:bg-surface-50"
        :class="[
          opt.value === modelValue ? 'border-accent ring-1 ring-accent bg-accent/5' : '',
          disabled ? 'opacity-50 cursor-not-allowed' : ''
        ]"
      >
        <div class="flex h-5 items-center">
          <input
            :id="`radio-${name}-${opt.value}`"
            :name="name"
            type="radio"
            :value="opt.value"
            :checked="opt.value === modelValue"
            :disabled="disabled"
            class="h-4 w-4 text-accent border-border focus:ring-accent"
          />
        </div>
        <div class="text-sm select-none">
          <label :for="`radio-${name}-${opt.value}`" class="font-medium text-ink-900 cursor-pointer block leading-snug">
            {{ opt.label }}
          </label>
          <p v-if="opt.description" class="text-xs text-ink-600 mt-0.5">
            {{ opt.description }}
          </p>
        </div>
      </div>
    </div>

    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
